<?php

namespace local_plagiarismchecker;

defined('MOODLE_INTERNAL') || die();

class docx_extractor {

    public static function extract(string $filepath): string {

        if (!file_exists($filepath)) {
            return '';
        }

        $zip = new \ZipArchive();

        if ($zip->open($filepath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');

        $zip->close();

        if (!$xml) {
            return '';
        }

        $dom = new \DOMDocument();

        @$dom->loadXML($xml);

        $text = strip_tags($dom->saveXML());

        return html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }
}