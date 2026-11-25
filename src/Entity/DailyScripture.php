<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\State\DailyScriptureProvider;
use DateTime;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\TypeIdentifier;

#[ApiResource(operations: [
    new Get(
        openapi: new OpenApiOperation(
            parameters: [
                new OpenApiParameter(
                    name: 'date',
                    in: 'path',
                    description: 'Date in mm-dd-yyyy format',
                    required: true,
                    schema: ['type' => 'string'],
                    example: '01-01-2023',
                ),
            ],
        ),
        provider: DailyScriptureProvider::class,
),
])]
class DailyScripture
{
    public function __construct(
        // Exposed as an m-d-Y string by getDate(); keeps API Platform from parsing {date} into a DateTime.
        #[ApiProperty(identifier: true, nativeType: new BuiltinType(TypeIdentifier::STRING))]
        private readonly DateTime $date,
        private readonly string $scripture,
        private readonly string $body,
    ) {
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
