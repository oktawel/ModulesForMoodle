<?php

namespace local_plagiarismchecker;

defined('MOODLE_INTERNAL') || die();

class doc_converter {

    public static function convert_to_docx(
        string $docfile
    ): ?string {

        try {

        if (!class_exists('COM')) {
            throw new \Exception('COM extension is not available');
        }

            $word = new \COM("Word.Application");

            $word->Visible = false;

            $document =
                $word->Documents->Open(
                    realpath($docfile)
                );

            $docxfile =
                dirname($docfile)
                . '/'
                . uniqid()
                . '.docx';

            // wdFormatXMLDocument = 16
            $document->SaveAs(
                $docxfile,
                16
            );

            $document->Close(false);

            $word->Quit();

            return $docxfile;

        } catch (\Throwable $e) {

            file_put_contents(
                'C:/tmp/plagiarism.log',
                'DOC CONVERT ERROR: ' .
                $e->getMessage() .
                PHP_EOL,
                FILE_APPEND
            );

            return null;
        }
    }
}