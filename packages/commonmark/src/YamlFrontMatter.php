<?php

declare(strict_types=1);

namespace Hydra\CommonMark;

use Hydra\View\InvalidFrontMatter;
use League\CommonMark\Extension\FrontMatter\Data\FrontMatterDataParserInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The YAML at the top of a content file, as a mapping. Unquoted dates come
 * back as DateTimeImmutable (PARSE_DATETIME): left to itself symfony/yaml
 * turns `2026-10-10` into a Unix timestamp, an int no caller would expect a
 * date to be.
 */
final class YamlFrontMatter implements FrontMatterDataParserInterface
{
    /** @return array<string, mixed> */
    public function parse(string $frontMatter): array
    {
        try {
            $data = Yaml::parse($frontMatter, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw new InvalidFrontMatter('The front matter is not valid YAML: ' . $e->getMessage(), previous: $e);
        }

        if ($data === null) {
            return [];
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidFrontMatter('The front matter must be a mapping of names to values, like "title: Hello".');
        }

        /** @var array<string, mixed> */
        return $data;
    }
}
