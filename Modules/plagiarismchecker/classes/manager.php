<?php

namespace local_plagiarismchecker;

defined('MOODLE_INTERNAL') || die();

class similarity_engine {

    private const SHINGLE_SIZE = 5;

    public static function compare(
        string $text1,
        string $text2
    ): float {

        $text1 = self::normalize($text1);
        $text2 = self::normalize($text2);

        $shingles1 = self::build_shingles($text1);
        $shingles2 = self::build_shingles($text2);

        if (
            empty($shingles1)
            || empty($shingles2)
        ) {
            return 0;
        }

        $intersection = count(
            array_intersect(
                $shingles1,
                $shingles2
            )
        );

        $union = count(
            array_unique(
                array_merge(
                    $shingles1,
                    $shingles2
                )
            )
        );

        if ($union == 0) {
            return 0;
        }

        return round(
            ($intersection / $union) * 100,
            2
        );
    }

    private static function normalize(
        string $text
    ): string {

        $text = mb_strtolower(
            $text,
            'UTF-8'
        );

        $text = preg_replace(
            '/[^a-zа-яё0-9\s]/iu',
            ' ',
            $text
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return trim($text);
    }

    private static function build_shingles(
        string $text
    ): array {

        $words = preg_split(
            '/\s+/u',
            $text
        );

        $result = [];

        $count = count($words);

        if ($count < self::SHINGLE_SIZE) {
            return [];
        }

        for (
            $i = 0;
            $i <= $count - self::SHINGLE_SIZE;
            $i++
        ) {

            $shingle = implode(
                ' ',
                array_slice(
                    $words,
                    $i,
                    self::SHINGLE_SIZE
                )
            );

            $result[] = sprintf(
                '%u',
                crc32($shingle)
            );
        }

        return array_unique($result);
    }
}