<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Vertaalt een SMTP-foutmelding (plus de SMTP-dialoog) naar uitleg en stappen in gewone taal.
 * Elke regel: een lijst van alternatieven; binnen een alternatief moeten alle stukjes voorkomen.
 */
final class Diagnose
{
    /** @var list<array{code: string, match: list<list<string>>, title: string, text: string, steps: list<string>}> */
    private const RULES = [
        [
            'code' => 'dns',
            'match' => [['php_network_getaddresses'], ['getaddrinfo'], ['Name or service not known'], ['No such host']],
            'title' => 'De SMTP-server is niet gevonden',
            'text' => 'De servernaam bestaat niet of is niet op te zoeken vanaf deze webserver.',
            'steps' => ['Controleer de spelling van de SMTP-server (bijv. smtp.office365.com, smtp.gmail.com, mail.jouwdomein.nl).', 'Gebruik geen http:// of een poort in het veld Server.'],
        ],
        [
            'code' => 'refused',
            'match' => [['Connection refused'], ['(111)']],
            'title' => 'De server weigert de verbinding',
            'text' => 'Op deze poort luistert niets, of een firewall weigert de verbinding actief.',
            'steps' => ['Controleer de poort: 587 met STARTTLS of 465 met SSL/TLS zijn het gebruikelijkst.', 'Vraag de hostingpartij of uitgaand verkeer op deze poort open staat.'],
        ],
        [
            'code' => 'timeout',
            'match' => [['Connection timed out'], ['timed out'], ['(110)'], ['Network is unreachable']],
            'title' => 'Geen verbinding met de server (time-out)',
            'text' => 'De server reageert niet. Meestal blokkeert de hosting uitgaande mail op deze poort.',
            'steps' => ['Probeer poort 587 (STARTTLS) of 465 (SSL/TLS).', 'Vraag de hostingpartij of uitgaande SMTP-poorten geblokkeerd zijn; sommige hosts staan alleen hun eigen mailserver toe.'],
        ],
        [
            'code' => 'certificate',
            'match' => [['certificate verify failed'], ['peer certificate'], ['certificate has expired'], ['self signed certificate'], ['cURL error 60']],
            'title' => 'Het SSL-certificaat van de server klopt niet',
            'text' => 'De server stuurt een certificaat dat niet geldig is, verlopen is, of niet bij de servernaam hoort.',
            'steps' => ['Gebruik de servernaam die op het certificaat staat (vaak de naam van de hostingserver in plaats van mail.jouwdomein.nl).', 'Laat de mailprovider het certificaat vernieuwen als het verlopen is.'],
        ],
        [
            'code' => 'encryption',
            'match' => [['wrong version number'], ['unknown protocol'], ['SSL routines'], ['SSL operation failed'], ['STARTTLS', 'SMTP ERROR'], ['Encryption required']],
            'title' => 'De versleuteling past niet bij de poort',
            'text' => 'De versleuteling en de poort horen niet bij elkaar.',
            'steps' => ['Gebruik SSL/TLS met poort 465, of STARTTLS met poort 587.', 'Kies "Geen" alleen als de provider dat uitdrukkelijk zegt (meestal poort 25).'],
        ],
        [
            'code' => 'app_password',
            'match' => [['Application-specific password'], ['InvalidSecondFactor'], ['5.7.9'], ['basic authentication is disabled'], ['SmtpClientAuthentication is disabled']],
            'title' => 'De provider staat inloggen met een gewoon wachtwoord niet toe',
            'text' => 'Bij Google en Microsoft 365 is voor SMTP vaak een app-wachtwoord nodig, of moet SMTP-authenticatie voor de mailbox aan.',
            'steps' => ['Google: maak een app-wachtwoord aan (vereist tweestapsverificatie) en vul dat hier in.', 'Microsoft 365: zet "Authenticated SMTP" aan voor deze mailbox in het Exchange-beheercentrum.'],
        ],
        [
            'code' => 'auth',
            'match' => [['Could not authenticate'], ['535'], ['Authentication failed'], ['authentication failed'], ['Username and Password not accepted']],
            'title' => 'Inloggen bij de SMTP-server lukt niet',
            'text' => 'De gebruikersnaam of het wachtwoord klopt niet, of de server accepteert deze inlogmethode niet.',
            'steps' => ['Controleer gebruikersnaam (meestal het volledige e-mailadres) en wachtwoord.', 'Is het wachtwoord onlangs gewijzigd? Vul het hier opnieuw in.', 'Gebruik bij Google of Microsoft 365 een app-wachtwoord.'],
        ],
        [
            'code' => 'sender',
            'match' => [['Sender address rejected'], ['not owned by user'], ['553'], ['5.7.60'], ['SendAsDenied'], ['not allowed to send as']],
            'title' => 'Dit afzenderadres mag niet via deze server versturen',
            'text' => 'De mailserver accepteert alleen afzenders die bij het account horen waarmee wordt ingelogd.',
            'steps' => ['Zet het afzender-e-mailadres gelijk aan de SMTP-gebruiker, of aan een adres dat dit account mag gebruiken.', 'Laat "Afzender altijd vervangen" aan staan, zodat plugins geen ander adres gebruiken.'],
        ],
        [
            'code' => 'relay',
            'match' => [['Relay access denied'], ['relaying denied'], ['not permitted to relay'], ['5.7.1', 'relay'], ['Unauthenticated senders not allowed']],
            'title' => 'De server stuurt deze mail niet door (relay geweigerd)',
            'text' => 'De server wil eerst een login voordat hij mail naar andere domeinen verstuurt.',
            'steps' => ['Zet Authenticatie aan en vul gebruikersnaam en wachtwoord in.'],
        ],
        [
            'code' => 'recipient',
            'match' => [['Recipient address rejected'], ['User unknown'], ['550 5.1.1'], ['mailbox unavailable'], ['does not exist']],
            'title' => 'Het adres van de ontvanger bestaat niet',
            'text' => 'De ontvangende server kent dit e-mailadres niet.',
            'steps' => ['Controleer het e-mailadres van de ontvanger op typfouten.'],
        ],
        [
            'code' => 'limit',
            'match' => [['421'], ['451'], ['452'], ['rate limit'], ['Too many'], ['quota'], ['exceeded'], ['try again later']],
            'title' => 'De server houdt de mail tijdelijk tegen',
            'text' => 'Waarschijnlijk is een verzendlimiet bereikt of is de server tijdelijk overbelast.',
            'steps' => ['Probeer het over een paar minuten opnieuw.', 'Controleer bij de mailprovider de verzendlimiet per uur of per dag.'],
        ],
        [
            'code' => 'rejected',
            'match' => [['554'], ['Data not accepted'], ['spam'], ['blocked'], ['blacklist']],
            'title' => 'De server weigert het bericht',
            'text' => 'De server heeft het bericht afgewezen, bijvoorbeeld als spam of vanwege een blokkade.',
            'steps' => ['Lees de exacte melding van de server hieronder.', 'Controleer SPF en DKIM van het afzenderdomein.', 'Neem contact op met de mailprovider met de melding erbij.'],
        ],
        [
            'code' => 'invalid_address',
            'match' => [['Invalid address'], ['You must provide at least one recipient']],
            'title' => 'Een e-mailadres is ongeldig',
            'text' => 'Het adres van de afzender of de ontvanger is geen geldig e-mailadres.',
            'steps' => ['Controleer het afzenderadres in deze instellingen.', 'Controleer het ontvangeradres in het formulier of de plugin die de mail verstuurt.'],
        ],
        [
            'code' => 'connect',
            'match' => [['SMTP connect() failed'], ['Failed to connect to server'], ['SMTP Error: Could not connect']],
            'title' => 'Geen verbinding met de SMTP-server',
            'text' => 'De server is niet bereikbaar met deze instellingen.',
            'steps' => ['Controleer server, poort en versleuteling (587 + STARTTLS of 465 + SSL/TLS).', 'Vraag de hostingpartij of uitgaande SMTP-poorten open staan.', 'Gebruik "Verbinding controleren" voor de stap waarop het misgaat.'],
        ],
    ];

    /**
     * @return array{code: string, title: string, text: string, steps: list<string>}
     */
    public static function explain(string $error, string $debug = ''): array
    {
        $haystack = $error . "\n" . self::relevant($debug);
        foreach (self::RULES as $rule) {
            foreach ($rule['match'] as $all) {
                $hit = true;
                foreach ($all as $needle) {
                    if (!self::contains($haystack, $needle)) {
                        $hit = false;
                        break;
                    }
                }
                if ($hit) {
                    return ['code' => $rule['code'], 'title' => $rule['title'], 'text' => $rule['text'], 'steps' => $rule['steps']];
                }
            }
        }

        return self::unknown();
    }

    /**
     * Alleen de regels uit de SMTP-dialoog die over een fout gaan: antwoorden 4xx/5xx van de server
     * en meldingen van PHPMailer zelf. Zo tellen bijvoorbeeld "250-STARTTLS" of "SIZE 35882421" niet mee.
     */
    public static function relevant(string $debug): string
    {
        $out = [];
        foreach (preg_split('/\R/', $debug) ?: [] as $line) {
            if (preg_match('/SERVER -> CLIENT:\s*[45]\d\d/', $line) === 1
                || preg_match('/SMTP (ERROR|Error)|Connection failed|stream_socket|SSL|certificate|getaddrinfo|php_network/i', $line) === 1) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /** Getallen (SMTP-codes) alleen als los getal, niet midden in een ander getal. */
    private static function contains(string $haystack, string $needle): bool
    {
        if (preg_match('/^[\d. ]+$/', $needle) === 1) {
            return preg_match('/(?<![\d.])' . preg_quote($needle, '/') . '(?![\d])/', $haystack) === 1;
        }

        return stripos($haystack, $needle) !== false;
    }

    /** @return array{code: string, title: string, text: string, steps: list<string>} */
    private static function unknown(): array
    {
        return [
            'code' => 'unknown',
            'title' => 'Versturen is mislukt',
            'text' => 'De oorzaak is niet automatisch te herkennen.',
            'steps' => ['Lees de melding van de server hieronder.', 'Controleer server, poort, versleuteling en login.', 'Gebruik "Verbinding controleren" voor de stap waarop het misgaat.'],
        ];
    }
}
