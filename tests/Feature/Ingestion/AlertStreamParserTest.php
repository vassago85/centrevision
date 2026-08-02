<?php

use App\Enums\PlateDirection;
use App\Services\Isapi\AlertStreamParser;

/**
 * One ANPR alert as Hikvision sends it on the ISAPI stream.
 */
function alertXml(string $plate = 'JD45GP', string $direction = 'forward', string $dateTime = '2026-08-02T10:15:30+02:00'): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <EventNotificationAlert version="2.0">
        <ipAddress>10.1.20.11</ipAddress>
        <channelID>1</channelID>
        <dateTime>{$dateTime}</dateTime>
        <eventType>ANPR</eventType>
        <eventState>active</eventState>
        <ANPR>
            <country>ZA</country>
            <licensePlate>{$plate}</licensePlate>
            <line>1</line>
            <direction>{$direction}</direction>
            <confidenceLevel>92</confidenceLevel>
            <plateType>unknown</plateType>
        </ANPR>
    </EventNotificationAlert>
    XML;
}

/**
 * Wrap alerts in the multipart envelope the camera streams.
 */
function multipart(string ...$documents): string
{
    $body = '';

    foreach ($documents as $document) {
        $body .= "--MIME_boundary\r\nContent-Type: application/xml\r\nContent-Length: "
            .strlen($document)."\r\n\r\n{$document}\r\n\r\n";
    }

    return $body;
}

it('reads a plate out of one alert', function () {
    $captures = (new AlertStreamParser)->push(multipart(alertXml()));

    expect($captures)->toHaveCount(1)
        ->and($captures[0]->plateNumber)->toBe('JD45GP')
        ->and($captures[0]->direction)->toBe(PlateDirection::In)
        ->and($captures[0]->confidence)->toBe(0.92)
        ->and($captures[0]->capturedAt->toIso8601String())->toContain('2026-08-02');
});

it('maps hikvision reverse direction onto an exit', function () {
    $captures = (new AlertStreamParser)->push(multipart(alertXml(direction: 'reverse')));

    expect($captures[0]->direction)->toBe(PlateDirection::Out);
});

it('leaves direction unset for a direction it does not recognise', function () {
    $captures = (new AlertStreamParser)->push(multipart(alertXml(direction: 'unknown')));

    expect($captures[0]->direction)->toBeNull();
});

it('reads several alerts arriving in one chunk', function () {
    $captures = (new AlertStreamParser)->push(multipart(
        alertXml('JD45GP'),
        alertXml('HK12GP'),
        alertXml('BX91GP'),
    ));

    expect(array_map(fn ($c) => $c->plateNumber, $captures))
        ->toBe(['JD45GP', 'HK12GP', 'BX91GP']);
});

it('waits for the rest of an alert split across chunks', function () {
    $parser = new AlertStreamParser;
    $body = multipart(alertXml('JD45GP'));

    $split = (int) (strlen($body) / 2);

    expect($parser->push(substr($body, 0, $split)))->toBe([]);

    $captures = $parser->push(substr($body, $split));

    expect($captures)->toHaveCount(1)
        ->and($captures[0]->plateNumber)->toBe('JD45GP');
});

it('handles an alert arriving one byte at a time', function () {
    $parser = new AlertStreamParser;
    $body = multipart(alertXml('HK12GP'));
    $captures = [];

    foreach (str_split($body) as $byte) {
        $captures = array_merge($captures, $parser->push($byte));
    }

    expect($captures)->toHaveCount(1)
        ->and($captures[0]->plateNumber)->toBe('HK12GP');
});

it('ignores heartbeat alerts that carry no ANPR block', function () {
    $heartbeat = <<<'XML'
    <EventNotificationAlert version="2.0">
        <eventType>videoloss</eventType>
        <eventState>inactive</eventState>
    </EventNotificationAlert>
    XML;

    expect((new AlertStreamParser)->push(multipart($heartbeat)))->toBe([]);
});

it('ignores an ANPR block with an empty plate', function () {
    expect((new AlertStreamParser)->push(multipart(alertXml(plate: ''))))->toBe([]);
});

it('keeps the raw payload for auditing', function () {
    $captures = (new AlertStreamParser)->push(multipart(alertXml()));

    expect($captures[0]->rawPayload)->toHaveKey('ANPR')
        ->and($captures[0]->rawPayload['ANPR']['country'])->toBe('ZA')
        ->and($captures[0]->rawPayload['channelID'])->toBe('1');
});

it('recovers after a malformed document', function () {
    $parser = new AlertStreamParser;

    $parser->push('<EventNotificationAlert><broken</EventNotificationAlert>');

    $captures = $parser->push(multipart(alertXml('BX91GP')));

    expect($captures)->toHaveCount(1)
        ->and($captures[0]->plateNumber)->toBe('BX91GP');
});

it('does not let a stream without a closing tag grow without bound', function () {
    $parser = new AlertStreamParser;

    for ($i = 0; $i < 40; $i++) {
        $parser->push(str_repeat('x', 50_000));
    }

    expect(strlen($parser->buffered()))->toBeLessThanOrEqual(1_048_576);
});
