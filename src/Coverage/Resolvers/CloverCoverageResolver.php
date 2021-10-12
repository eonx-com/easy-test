<?php

declare(strict_types=1);

namespace EonX\EasyTest\Coverage\Resolvers;

use EonX\EasyTest\Coverage\DataTransferObjects\CoverageReportDto;
use EonX\EasyTest\Exceptions\UnableToResolveCoverageException;
use EonX\EasyTest\Interfaces\CoverageResolverInterface;
use Exception;
use SimpleXMLElement;

final class CloverCoverageResolver implements CoverageResolverInterface
{
    /**
     * @var string
     */
    private const ATTRIBUTE_NAME_COVERED_ELEMENTS = 'coveredelements';

    /**
     * @var string
     */
    private const ATTRIBUTE_NAME_ELEMENTS = 'elements';

    /**
     * @var string
     */
    private const ATTRIBUTE_NAME_FILE_NAME = 'name';

    public function resolve(string $coverageOutput): CoverageReportDto
    {
        $violations = [];

        try {
            $xml = new SimpleXMLElement($coverageOutput);
        } catch (Exception $e) {
            throw new UnableToResolveCoverageException(\sprintf(
                '[%s] Given output could not be parsed',
                static::class
            ));
        }

        $metrics = $xml->xpath('//file/metrics');

        foreach ($metrics as $metric) {
            $elements = (int)$this->extractXmlAttribute($metric, self::ATTRIBUTE_NAME_ELEMENTS);
            $coveredElements = (int)$this->extractXmlAttribute($metric, self::ATTRIBUTE_NAME_COVERED_ELEMENTS);

            if ($elements !== $coveredElements) {
                $file = $metric->xpath('parent::*')[0];
                $violations[] = $this->extractXmlAttribute($file, self::ATTRIBUTE_NAME_FILE_NAME);
            }
        }

        $totalElements = (int)$xml->xpath('//project/metrics/@elements')[0];
        $totalCoveredElements = (int)$xml->xpath('//project/metrics/@coveredelements')[0];
        $coverage = ($totalCoveredElements / $totalElements) * 100;

        return new CoverageReportDto($coverage, $violations);
    }

    private function extractXmlAttribute(SimpleXMLElement $element, string $attributeName): string
    {
        $attr = $element->attributes();

        if ($attr === null) {
            throw new UnableToResolveCoverageException(\sprintf(
                '[%s] Given output could not be parsed',
                static::class
            ));
        }

        if (isset($attr[$attributeName]) === false) {
            throw new UnableToResolveCoverageException(\sprintf(
                '[%s] Given output could not be parsed',
                static::class
            ));
        }

        return (string)$attr[$attributeName];
    }
}