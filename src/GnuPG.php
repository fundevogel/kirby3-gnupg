<?php

namespace Fundevogel;

use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

/**
 * Class GnuPG
 *
 * Utilities for GnuPG
 */
class GnuPG
{
    /**
     * Extracted data
     *
     * @var array
     */
    public $data;


    /**
     * Constructor
     *
     * @param string $file Path to public PGP key
     *
     *  @return void
     */
    public function __construct(string $file)
    {
        # Create data array
        $this->data = A::update([
            'key' => '',
            'fpr' => $this->fingerprint($file),
        ], $this->packets($file));
    }


    /**
     * Gets fingerprint
     *
     * @param string $file Public PGP key
     *
     * @return string
     */
    public function fingerprint(string $file): string
    {
        # Extract further information
        exec('gpg --with-colons ' . $file, $output, $status);

        # If command fails ..
        if ($status !== 0) {
            # .. abort execution
            throw new \Exception(A::join($output, "\n"));
        }

        # Iterate over lines of output
        foreach ($output as $index => $line) {
            # Prepare shell output by removing whitespaces
            $line = trim($line);

            # Get fingerprint
            if (preg_match('/^fpr:+([A-Z0-9]+):$/', $line, $matches)) {
                return trim(chunk_split($matches[1], 4, ' '));
            }
        }

        return '';
    }


    /**
     * Gets packets data
     *
     * @param string $file Path to public PGP key
     *
     *  @return array
     */
    public function packets(string $file): array
    {
        # Execute command
        exec("gpg --list-packets $file", $packets, $status);

        # If command fails ..
        if ($status !== 0) {
            # .. abort execution
            throw new \Exception(A::join($packets, "\n"));
        }

        # Create data array for primary key
        $data = [];

        # Iterate over lines of output
        foreach ($packets as $index => $line) {
            # Prepare shell output
            # (1) Remove whitespaces
            # (2) Convert HTML, otherwise emails are gone because of surrounding `<` and `>`
            $line = trim(htmlspecialchars($line));

            # Extract information primary key
            if (Str::startsWith($line, ':public key packet:')) {
                # Extract primary key data
                $data = A::update($data, $this->extract($packets, $index));
            }

            # Extract information about user
            if (Str::startsWith($line, ':user ID packet:')) {
                # Process line
                $line = trim(Str::replace($line, [':user ID packet:', '&quot;'], ['', '']));

                # Get name, comment & email address
                # (1) Check for "name (comment) <email>" format
                if (preg_match('/^([^\(]+)\(([^\)]+)\)\s+&lt;([^>]+)&gt;$/', $line, $matches)) {
                    $data['name']    = trim($matches[1]);
                    $data['comment'] = trim($matches[2]);
                    $data['email']   = trim($matches[3]);
                }

                # (2) Check for "name <email>" format
                elseif (preg_match('/^([^<]+)\s+&lt;([^>]+)&gt;$/', $line, $matches)) {
                    $data['name']  = trim($matches[1]);
                    $data['email'] = trim($matches[2]);
                }

                # (3) Check for "name" format
                elseif (preg_match('/^([^<]+)$/', $line, $matches)) {
                    $data['name'] = trim($matches[1]);
                }

                # (4) Check for "<email>" format
                elseif (preg_match('/^&lt;([^>]+)&gt;$/', $line, $matches)) {
                    $data['email'] = trim($matches[2]);
                }

                # Based on Stephen Paul Weber's regex,
                # see https://github.com/singpolyma/openpgp-php/blob/master/lib/openpgp.php#L1789
                ##

                # Stop iteration
                break;
            }
        }

        # Create data array for subkey(s)
        $data['subkeys'] = [];

        # Iterate over lines of output
        foreach ($packets as $index => $line) {
            # Prepare shell output by removing whitespaces
            $line = trim($line);

            # Extract information about subkeys
            if (Str::startsWith($line, ':public sub key packet:')) {
                # Parse subkey
                $subkey = $this->extract($packets, $index);

                # Update data array
                if (!empty($subkey)) {
                    $data['subkeys'][] = $subkey;
                }
            }
        }

        return $data;
    }


    /**
     * Extracts data about a key, depending on its line index
     *
     * @param array $packets Output of `gpg --list-packets`
     * @param int $index Current index
     *
     * @return array
     */
    private function extract(array $packets, int $index): array
    {
        # Create data array
        $result = [];

        # Remove whitespaces
        $line = trim($packets[$index + 1]);

        # Prepare detection pattern and ..
        $pattern = '/^version\s(\d),\salgo\s(\d+),\screated\s(\d+),\sexpires\s(\d+)$/';

        # ..if it matches the next line ..
        if (preg_match($pattern, $line, $matches)) {
            # .. get primary key version, creation date ..
            $result['version'] = $matches[1];
            $result['created'] = date('Y-m-d', $matches[3]);

            # .. but expiration date only if applicable
            if ($matches[4] != '0') {
                $result['expires'] = date('Y-m-d', $matches[4]);
            }

            # .. as well as used algorithm & its cryptographic family
            $result = A::update($result, $this->crypto($matches[2]));
        }

        # From unspecified line after current one ..
        for ($i = 0; $i < 1000; $i++) {
            # .. get primary key ID ..
            if (Str::contains($packets[$index + $i], 'keyid:')) {
                # .. when its presence is indicated
                $result['key'] = Str::split($packets[$index + $i], ' ')[1];

                # Stop iteration
                break;
            }
        }

        # Process line after next line
        # (1) Get length of primary key
        if (preg_match('/\[(\d+) bits\]/', $packets[$index + 2], $matches)) {
            $result['length'] = $matches[1];
        }

        # (2) Determine implemented curve (ECC only)
        if (preg_match('/^.+\s([0-9a-z]+)\s\([0-9.]+\)$/', $packets[$index + 2], $matches)) {
            $result['type'] = $matches[1];
        }

        return $result;
    }


    /**
     * Determines algorithm & its cryptographic family from a key's `algo` code
     *
     * @param string $code Algorithm code
     *
     * @return array
     */
    private function crypto(string $code): array
    {
        $data = [];

        # Determine algorithm &
        # (1) Map codes & their respective algorithms
        $algorithms = [
            '1'  => 'RSA',
            '2'  => 'RSAEncryptOnly',
            '3'  => 'RSASignOnly',
            '16' => 'ElGamal',
            '17' => 'DSA',
            # RFC 6637, Section 5
            '18' => 'ECDH',
            '19' => 'ECDSA',
            # https://www.ietf.org/archive/id/draft-koch-eddsa-for-openpgp-04.txt
            '22' => 'EdDSA',
        ];

        # (2) Apply type of algorithm
        $data['algorithm'] = $algorithms[$code];

        # Determine cryptography system
        # (1) RSA, short for 'Rivest–Shamir–Adleman'
        if (Str::startsWith($data['algorithm'], 'RSA')) {
            $data['crypto'] = 'Rivest–Shamir–Adleman';
        }

        # (2) ECC, short for 'Elliptic Curve Cryptography'
        elseif (in_array($data['algorithm'], ['ECDH', 'ECDSA', 'EdDSA'])) {
            $data['crypto'] = 'Elliptic Curve Cryptography';
        }

        # (3) Signature only ..
        else {
            # .. thus not suited for primary keys
            $signatures = [
                # (3a) ElGamal, also known as 'ElGamal signature scheme'
                # (3b) DSA, short for 'Digital Signature Algorithm'
                'ElGamal' => 'ElGamal signature scheme',
                'DSA'     => 'Digital Signature Algorithm',
            ];

            $data['crypto'] = $signatures[$data['algorithm']];
        }

        return $data;
    }
}
