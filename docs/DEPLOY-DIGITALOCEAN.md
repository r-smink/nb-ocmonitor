# NB OC Monitor installeren op een DigitalOcean-droplet (Docker)

Na deze stappen draait het panel op `https://<jouw-domein>`, met automatisch een HTTPS-certificaat via Caddy en Let's Encrypt. Inloggen gaat met e-mail, wachtwoord en verplichte tweestapsverificatie.

```
Internet ──443──► caddy ──► app (Laravel, nginx + php-fpm)
                              │
                 scheduler ───┤  zet heartbeats/inventaris klaar
                 signer ──────┤  enige container met de private panel-sleutel; praat met klantsites
                 mysql ◄──────┘
```

## Wat je nodig hebt

- Een DigitalOcean-account.
- Een (sub)domein waarvan je de DNS beheert, bijvoorbeeld `oc.nextbuzz.nl`.
- Een SSH-sleutel op je eigen computer.
- De code van deze repository. Zet hem bij voorkeur in een **privé** GitHub-repo. Rsync vanaf je eigen computer kan ook (zie stap 4).

## 1. Droplet aanmaken

1. **Create → Droplets**.
2. Region: **Amsterdam (AMS3)**.
3. Image: **Marketplace → "Docker"** (Ubuntu met Docker en Docker Compose voorgeïnstalleerd).
4. Size: **Basic, Regular, 2 GB RAM / 1 vCPU** of groter. MySQL 8 met Laravel past niet goed in 1 GB.
5. Authentication: **SSH Key** (geen wachtwoord).
6. Zet **Backups** en **Monitoring** aan.
7. Hostname: bijvoorbeeld `ocm-nextbuzz`.

Noteer het IP-adres van de droplet.

## 2. DNS

Maak bij je DNS-provider een **A-record** voor het domein naar het IP van de droplet, en een **AAAA-record** als de droplet IPv6 heeft. Controleer met `dig +short oc.nextbuzz.nl`. Caddy kan pas een certificaat aanvragen als de DNS klopt.

## 3. Droplet beveiligen

```bash
ssh root@<ip>

apt update && apt -y upgrade
apt -y install unattended-upgrades fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades

# Firewall: alleen SSH, HTTP en HTTPS.
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw --force enable
```

Let op: Docker zet gepubliceerde poorten zelf open, buiten ufw om. In deze opzet publiceert alleen Caddy poorten (80/443). MySQL en de app zijn alleen binnen het Docker-netwerk bereikbaar. Wil je dubbele zekerheid, maak dan ook in DigitalOcean een **Cloud Firewall** met alleen 22, 80 en 443 inkomend.

### Eigen gebruiker `deploy` (in plaats van root)

Je installeert en beheert OC Monitor als gewone gebruiker. Root mag daarna niet meer via SSH inloggen.

```bash
# Nog als root ingelogd:
adduser deploy                      # kies een sterk wachtwoord (nodig voor sudo) en zet het in de kluis
usermod -aG sudo,docker deploy      # sudo voor beheer, docker voor 'docker compose'

# Jouw SSH-sleutel (waarmee je nu als root inlogt) ook voor deploy instellen.
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy

# Map voor de applicatie, eigendom van deploy.
mkdir -p /opt/nb-ocmonitor && chown deploy:deploy /opt/nb-ocmonitor
```

Test in een **nieuw** terminalvenster, en laat het root-venster nog open:

```bash
ssh deploy@<ip>
sudo whoami        # moet "root" geven (na je wachtwoord)
docker ps          # moet werken zonder sudo
```

Werkt dat, schakel dan root-login en wachtwoord-login uit:

```bash
sudo tee /etc/ssh/sshd_config.d/99-hardening.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
EOF
sudo sshd -t && sudo systemctl restart ssh
```

Test opnieuw in een nieuw venster dat `ssh deploy@<ip>` werkt en `ssh root@<ip>` geweigerd wordt. Sluit pas dan het root-venster.

Let op: lid zijn van de groep `docker` staat in de praktijk gelijk aan root-rechten. Bescherm de SSH-sleutel van `deploy` dus net zo goed als die van root. Het voordeel van een eigen gebruiker is dat root niet meer van buitenaf kan inloggen en dat je niet per ongeluk alles als root doet.

## 4. Code op de droplet zetten

Alles vanaf hier doe je als `deploy` (`ssh deploy@<ip>`).

**Via een privé GitHub-repo (aanbevolen):**

1. Maak een deploy key op de droplet. Deze sleutel geeft alleen leestoegang tot deze ene repo:
   ```bash
   ssh-keygen -t ed25519 -C "ocm-droplet" -f ~/.ssh/ocm_deploy -N ""
   cat ~/.ssh/ocm_deploy.pub
   ```
2. Ga op GitHub naar de repo, dan **Settings → Deploy keys → Add deploy key**. Plak de publieke sleutel en laat **Allow write access uit**.
3. Laat SSH die sleutel voor GitHub gebruiken, en test de verbinding:
   ```bash
   cat >> ~/.ssh/config <<'EOF'
   Host github.com
     IdentityFile ~/.ssh/ocm_deploy
     IdentitiesOnly yes
   EOF
   chmod 600 ~/.ssh/config
   ssh -T git@github.com     # "Hi r-smink/nb-ocmonitor! You've successfully authenticated…"
   ```
4. Clone de repo:
   ```bash
   git clone git@github.com:r-smink/nb-ocmonitor.git /opt/nb-ocmonitor
   cd /opt/nb-ocmonitor
   ```

**Of via rsync vanaf je eigen computer** (in Git Bash, vanuit de map `nb-ocmonitor`):

```bash
rsync -av --delete --exclude .env --exclude signer.env --exclude 'panel/vendor' \
  --exclude 'panel/database/*.sqlite' --exclude backups ./ deploy@<ip>:/opt/nb-ocmonitor/
```

## 5. Configuratie aanmaken

```bash
cd /opt/nb-ocmonitor
bash deploy/setup.sh
```

Het script vraagt naar:

- het domein, het e-mailadres voor Let's Encrypt en het e-mailadres van de eerste admin;
- optioneel de **IP-adressen die het panel mogen openen**, bijvoorbeeld het vaste IP van kantoor. Klantsites kunnen dan nog steeds bij `/api/*`, maar de beheeromgeving is voor andere IP's afgeschermd (403). Leeg laten betekent dat iedereen de loginpagina ziet.

Het script maakt daarna:

- `.env` met willekeurige wachtwoorden, een `APP_KEY`, de **publieke** panel-sleutel en het tijdelijke admin-wachtwoord;
- `signer.env` met de **private** panel-sleutel, die alleen de signer-container leest.

Beide bestanden krijgen rechten 600. **Zet ze direct in de wachtwoordkluis.** Ze zijn nodig om een backup terug te zetten:

- zonder `APP_KEY` zijn 2FA-geheimen niet meer te lezen;
- zonder de signing key moeten alle sites opnieuw gekoppeld worden.

Optioneel: zet voor PageSpeed een API-sleutel in `.env` (`OCM_PAGESPEED_API_KEY`). Maak die aan in Google Cloud (API's → PageSpeed Insights API → Inloggegevens) en beperk de sleutel tot die ene API. Zonder sleutel werkt het ook, maar met een laag quotum. De scheduler-container meet elke site eens per `OCM_PAGESPEED_INTERVAL_DAYS` dagen (standaard 7).

Backups vóór updates (DigitalOcean Spaces):

1. Maak in DigitalOcean een Space aan (Spaces Object Storage), in dezelfde regio als de droplet, met **File Listing: Restricted** en zonder CDN.
2. Maak onder API → Spaces Keys een sleutel aan, liefst beperkt tot deze ene Space (Limited access, Read/Write/Delete).
3. Zet in `.env`: `OCM_SPACES_KEY`, `OCM_SPACES_SECRET`, `OCM_SPACES_REGION` (bijv. `ams3`) en `OCM_SPACES_BUCKET`, en voer `docker compose up -d --force-recreate` uit.

Sites met client-plugin 0.5.0 of nieuwer maken dan vóór elke update een backup van de database en de onderdelen die bijgewerkt worden. Die is versleuteld met een sleutel die alleen op de site zelf staat (de site uploadt rechtstreeks via een tijdelijke link; de Spaces-sleutel komt nooit op een klantsite). Een backup blijft 7 dagen staan, of tot je de visuele controle op "Bekeken, in orde" zet. Mislukt de backup, dan gaan de updates niet door.

Kwetsbaarheden: maak een gratis account op wordfence.com, maak onder Account → Integrations een API-sleutel voor de Vulnerability Data Feed en zet die in `.env` als `OCM_WORDFENCE_API_KEY`. De scheduler haalt de feed elke nacht om 04:30 op; de eerste keer direct met `docker compose exec scheduler php artisan ocm:vulnerabilities`. Gebruik is gratis, ook zakelijk; het panel en de rapporten vermelden Wordfence als bron.

Monitoring: de scheduler controleert elke 5 minuten de homepage van elke site, het SSL-certificaat twee keer per dag en de domeinregistratie (RDAP) wekelijks. Visuele controle bij updates werkt alleen met `OCM_PAGESPEED_API_KEY`; uitzetten met `OCM_VISUAL_CHECKS=false`, drempel met `OCM_VISUAL_THRESHOLD` (standaard 15%).

Nieuwe versie van de client-plugin: maak lokaal `php scripts/release-plugin.php` (de release-sleutel staat alleen op je eigen computer, in `~/.nb-ocm/`), commit `client-plugin/dist/` en deploy. Sites met 0.4.0 of nieuwer krijgen de update dan aangeboden onder Updates → OC-plugin. **Bewaar de release-sleutel ook in een wachtwoordkluis**: zonder die sleutel kunnen sites niet meer via het panel bijgewerkt worden.

Voor klantrapporten per e-mail: zet de `MAIL_*`-regels in `.env` (SMTP van je mailprovider) en voer `docker compose up -d --force-recreate` uit. Zonder SMTP blijft `MAIL_MAILER=log` staan: rapporten bekijken en downloaden werkt dan wel, versturen schrijft de mail alleen naar het log. Logo's, achtergronden en fonts voor de rapporten staan in de database en zitten dus in de gewone backup.

## 6. Starten

```bash
docker compose up -d --build
docker compose ps          # alle services "running"; app "healthy"
docker compose logs -f app # migraties en "Admin-account … aangemaakt"
```

Open `https://<domein>` en log in met het e-mailadres en het tijdelijke wachtwoord dat `setup.sh` toonde. Je moet direct tweestapsverificatie instellen door de QR-code te scannen met Microsoft Authenticator, 1Password of Google Authenticator. Wijzig daarna je wachtwoord via **Account**.

Collega's voeg je toe via **Gebruikers**. Ze krijgen een tijdelijk wachtwoord en stellen bij hun eerste login hun eigen 2FA in. De rollen:

- **Admin**: alles, inclusief gebruikersbeheer.
- **Beheerder**: sites toevoegen en opdrachten geven.
- **Alleen lezen**: alleen bekijken.

## 7. Eerste site koppelen

1. **Sites → Site toevoegen**: vul de naam en de exacte siteadres-URL (`https://…`) in.
2. Klik op **Plugin** (rechtsboven) of op de link in de stappen om `nb-ocmonitor-client-<versie>.zip` te downloaden.
3. Installeer de zip op de site via **Plugins → Nieuwe plugin → Plugin uploaden** en activeer hem.
4. Kopieer de koppelcode uit het panel. Die is 15 minuten geldig, eenmalig te gebruiken, en alleen voor die host.
5. Plak de code op de site onder **Extra → NB OC Monitor**, of gebruik `wp nb-ocm pair <code>`.
6. De site verschijnt als gekoppeld. Binnen enkele seconden haalt de signer de status en de inventaris op.

Lukt het koppelen niet? Controleer het volgende:

- De site moet via HTTPS bereikbaar zijn, en de URL in het panel moet gelijk zijn aan het siteadres in WordPress.
- Security-plugins die de REST API blokkeren moeten `nb-ocm/v1` toestaan.
- In **Activiteit → Audit log panel** staat de reden van een afgewezen koppeling, bijvoorbeeld `host_mismatch`.

## 8. Backups

DigitalOcean-backups maken een wekelijkse kopie van de hele droplet. Daarnaast een dagelijkse database-dump:

```bash
crontab -e
# toevoegen:
30 3 * * * cd /opt/nb-ocmonitor && bash deploy/backup.sh >> backups/backup.log 2>&1
```

De dumps staan in `/opt/nb-ocmonitor/backups` (14 dagen). Kopieer ze bij voorkeur ook naar externe opslag, zoals DigitalOcean Spaces met `s3cmd` of `rclone`.

**Terugzetten:**

```bash
gunzip -c backups/ocmonitor-<datum>.sql.gz | docker compose exec -T mysql sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

Gebruik daarbij dezelfde `.env` en `signer.env`.

## 9. Updaten

```bash
cd /opt/nb-ocmonitor
git pull                       # of opnieuw rsyncen
docker compose up -d --build   # migraties draaien automatisch bij het starten van de app
docker image prune -f
```

Een nieuwe versie van de client-plugin zit automatisch in de download van het panel. Op de sites werk je hem voorlopig bij door de zip opnieuw te uploaden. Automatische plugin-updates komen in een volgende fase.

## 10. Handige commando's

```bash
docker compose logs -f signer                       # wat de signer naar sites stuurt (ok/fout per opdracht)
docker compose logs -f scheduler
docker compose exec app php artisan ocm:verify-log  # controleer de hash-keten van het audit log
docker compose exec app php artisan ocm:queue-checks --inventory   # nu alle inventarissen vernieuwen
docker compose exec mysql sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 11. Problemen oplossen

| Probleem | Oplossing |
|---|---|
| Geen HTTPS-certificaat | Klopt de DNS? Zijn poort 80 en 443 open? Bekijk `docker compose logs caddy`. |
| 403 op het panel | Je IP staat niet in `ADMIN_ALLOW_IPS`. Pas `.env` aan en draai `docker compose up -d caddy`. |
| Wachtwoord of 2FA-telefoon kwijt (enige admin) | Zet in `.env` `OCM_RESET_ADMIN=1` en een nieuw `OCM_ADMIN_PASSWORD`, en draai `docker compose up -d app`. Log in en stel 2FA opnieuw in. Zet daarna `OCM_RESET_ADMIN=0` en draai `docker compose up -d app` opnieuw. Gaat het om een andere gebruiker, dan kan een admin diens 2FA resetten via **Gebruikers**. |
| Site blijft "Niet bereikbaar" | Bekijk `docker compose logs signer`. Een 404 betekent meestal: de plugin is niet actief, de site is ontkoppeld, of een security-plugin blokkeert de REST API. |
| Signer start niet ("hoort niet bij OCM_PANEL_PUBLIC_KEY") | `signer.env` en `.env` horen niet bij elkaar. Zet beide terug uit de kluis. |
| Private panel-sleutel gelekt | Volg het runbook in [docs/threat-model.md](threat-model.md): stop de signer, zet `NB_OCM_DISABLED` op de sites, maak een nieuw sleutelpaar (`php artisan ocm:keygen`) en koppel de sites opnieuw. |

## Checklist

- [ ] DNS wijst naar de droplet, en het certificaat is uitgegeven
- [ ] ufw en Cloud Firewall laten alleen 22, 80 en 443 toe
- [ ] `.env` en `signer.env` staan in de wachtwoordkluis
- [ ] Eerste login gedaan, 2FA ingesteld en wachtwoord gewijzigd
- [ ] `ADMIN_ALLOW_IPS` ingesteld (aanbevolen)
- [ ] Backup-cron actief en een terugzet-test gedaan
- [ ] Eerste site gekoppeld, met heartbeat en inventaris zichtbaar
