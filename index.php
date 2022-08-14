<?php

load([
    'fundevogel\\gnupg' => 'src/GnuPG.php'
], __DIR__);


use Kirby\Cms\File;
use Fundevogel\GnuPG;

/**
 * Kirby v3 utilities for GnuPG
 *
 * @package   kirby3-gnupg
 * @author    Martin Folkers <maschinenraum@fundevogel.de>
 * @link      https://fundevogel.de
 * @copyright Kinder- und Jugendbuchhandlung Fundevogel
 * @license   https://opensource.org/licenses/MIT
 */
Kirby::plugin('fundevogel/gnupg', [
    'blueprints' => [
        'files/pubkey'        => __DIR__ . '/blueprints/pubkey.yml',
        'files/gnupg/key'     => __DIR__ . '/blueprints/base/key.yml',
        'files/gnupg/created' => __DIR__ . '/blueprints/base/created.yml',
        'files/gnupg/expires' => __DIR__ . '/blueprints/base/expires.yml',
        'files/gnupg/algo'    => __DIR__ . '/blueprints/base/algo.yml',
        'files/gnupg/crypto'  => __DIR__ . '/blueprints/base/crypto.yml',
        'files/gnupg/length'  => __DIR__ . '/blueprints/base/length.yml',
        'files/gnupg/type'    => __DIR__ . '/blueprints/base/type.yml',
        'sections/pubkeys'    => __DIR__ . '/blueprints/pubkeys.yml',
    ],
    'hooks' => [
        /**
         * Updates key information after upload
         *
         * @param \Kirby\Cms\File $file File object of uploaded file
         */
        'file.create:after' => function (File $file) {
            if ($file->extension() == 'asc') {
                $file->update((new GnuPG($file->root()))->data);
            }
        },


        /**
         * Updates key information upon replacement
         *
         * @param \Kirby\Cms\File $newFile File object of replacement file
         * @param \Kirby\Cms\File $oldFile File object of file to be replaced
         */
        'file.replace:after' => function (File $newFile, File $oldFile) {
            if ($newFile->extension() == 'asc') {
                $newFile->update((new GnuPG($newFile->root()))->data);
            }
        },
    ],
]);
