<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\DailyScriptureProvider;
use DateTime;

/** A Daily Scripture Entity */
#[ApiResource(operations: [
    new Get(
        openapiContext: [
            'parameters' => [
                [
                    'name' => 'date',
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                    'description' => 'Date in mm-dd-yyyy format',
                    'example' => '01-01-2023',
                ],
            ],
        ],
        provider: DailyScriptureProvider::class,
),
])]
class DailyScripture
{
    public function __construct(#[ApiProperty(identifier: true)]
    private readonly DateTime $date, private readonly string $scripture, private readonly string $body,)
    {
    }

    public function getDate(): string
    {
        return $this->date->format('m-d-Y');
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getScripture(): string
    {
        return $this->scripture;
    }
}
