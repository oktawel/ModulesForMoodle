<?php

namespace local_plagiarismchecker;

defined('MOODLE_INTERNAL') || die();

class doc_extractor {

    public static function extract(string $filepath): string {

        try {

            $word = new \COM("Word.Application");

            $word->Visible = false;

            $document = $word->Documents->Open(
                realpath($filepath)
            );

            $text = $document->Content->Text;

            $document->Close(false);

            $word->Quit();

            return trim($text);

        } catch (\Throwable $e) {

            return '';
        }
    }
}