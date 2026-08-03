<?php

use App\Enums\PlateDirection;
use App\Services\Ingestion\HikvisionWebhookParser;

/**
 * The XML body Hikvision's "HTTP Listening" host emits for an ANPR event on
 * the iDS-2CD7A46G0/P-IZHS. Same shape as the alert stream sends, so the
 * shared AlertStreamParser handles the semantics; this test suite is only
 * exercising the MIME plumbing on top.
 */
function hikXml(
    string $plate = 'JD45GP',
    string $direction = 'forward',
    string $dateTime = '2026-08-03T10:15:30+02:00',
    int $confidence = 92,
): string {
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <EventNotificationAlert version="2.0">
        <ipAddress>10.0.0.25</ipAddress>
        <channelID>1</channelID>
        <dateTime>{$dateTime}</dateTime>
        <eventType>ANPR</eventType>
        <eventState>active</eventState>
        <eventDescription>Vehicle Detection Alarm</eventDescription>
        <ANPR>
            <country>ZA</country>
            <licensePlate>{$plate}</licensePlate>
            <line>1</line>
            <direction>{$direction}</direction>
            <confidenceLevel>{$confidence}</confidenceLevel>
            <plateType>unknown</plateType>
            <vehicleType>car</vehicleType>
        </ANPR>
    </EventNotificationAlert>
    XML;
}

/**
 * Build a multipart body the way Hikvision assembles it: XML part first,
 * then any image parts, then the terminating boundary marker.
 *
 * @param  array<int, array{content_type: string, filename?: string, bytes: string}>  $images
 */
function hikMultipart(string $xml, array $images = [], string $boundary = 'MIME_boundary_ANPR'): string
{
    $body = '';

    $body .= "--{$boundary}\r\n"
        .'Content-Disposition: form-data; name="anpr.xml"; filename="anpr.xml"'."\r\n"
        ."Content-Type: application/xml; charset=\"UTF-8\"\r\n"
        .'Content-Length: '.strlen($xml)."\r\n\r\n"
        .$xml."\r\n";

    foreach ($images as $index => $image) {
        $filename = $image['filename'] ?? 'image'.$index.'.jpg';
        $body .= "--{$boundary}\r\n"
            .'Content-Disposition: form-data; name="'.$filename.'"; filename="'.$filename.'"'."\r\n"
            .'Content-Type: '.$image['content_type']."\r\n"
            .'Content-Length: '.strlen($image['bytes'])."\r\n\r\n"
            .$image['bytes']."\r\n";
    }

    $body .= "--{$boundary}--\r\n";

    return $body;
}

it('parses an ANPR event out of a multipart body with no attachments', function () {
    $body = hikMultipart(hikXml(plate: 'JD45GP', direction: 'forward'));
    $contentType = 'multipart/form-data; boundary=MIME_boundary_ANPR';

    $event = app(HikvisionWebhookParser::class)->parse($body, $contentType);

    expect($event)->not->toBeNull()
        ->and($event->capture->plateNumber)->toBe('JD45GP')
        ->and($event->capture->direction)->toBe(PlateDirection::In)
        ->and($event->capture->confidence)->toBe(0.92)
        ->and($event->attachments)->toBeEmpty();
});

it('extracts plate crop and vehicle snapshot images alongside the XML', function () {
    $body = hikMultipart(
        xml: hikXml(plate: 'HK12GP', direction: 'reverse'),
        images: [
            ['content_type' => 'image/jpeg', 'filename' => 'licensePlatePicture.jpg', 'bytes' => 'plate-jpeg-bytes'],
            ['content_type' => 'image/jpeg', 'filename' => 'detectionPicture.jpg', 'bytes' => 'vehicle-jpeg-bytes'],
        ],
    );

    $event = app(HikvisionWebhookParser::class)->parse(
        $body,
        'multipart/form-data; boundary="MIME_boundary_ANPR"',
    );

    expect($event->capture->plateNumber)->toBe('HK12GP')
        ->and($event->capture->direction)->toBe(PlateDirection::Out)
        ->and($event->attachments)->toHaveCount(2)
        ->and($event->attachments[0]->filename)->toBe('licensePlatePicture.jpg')
        ->and($event->attachments[0]->bytes)->toBe('plate-jpeg-bytes')
        ->and($event->attachments[0]->contentType)->toBe('image/jpeg')
        ->and($event->attachments[1]->filename)->toBe('detectionPicture.jpg');
});

it('accepts a bare XML body with no multipart wrapper', function () {
    $event = app(HikvisionWebhookParser::class)->parse(
        hikXml(plate: 'BX91GP'),
        'application/xml; charset=UTF-8',
    );

    expect($event->capture->plateNumber)->toBe('BX91GP')
        ->and($event->attachments)->toBeEmpty();
});

it('sniffs bare XML when the proxy strips the Content-Type header', function () {
    $event = app(HikvisionWebhookParser::class)->parse(hikXml(plate: 'CC01GP'), null);

    expect($event->capture->plateNumber)->toBe('CC01GP');
});

it('tolerates a quoted boundary token', function () {
    $body = hikMultipart(hikXml(plate: 'JD45GP'));

    $event = app(HikvisionWebhookParser::class)->parse(
        $body,
        'multipart/form-data; boundary="MIME_boundary_ANPR"',
    );

    expect($event->capture->plateNumber)->toBe('JD45GP');
});

it('returns null when the multipart carries no ANPR XML', function () {
    $body = "--MIME_boundary_ANPR\r\n"
        ."Content-Type: image/jpeg\r\n\r\n"
        .'orphan-image-bytes'."\r\n"
        .'--MIME_boundary_ANPR--'."\r\n";

    $event = app(HikvisionWebhookParser::class)->parse(
        $body,
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    );

    expect($event)->toBeNull();
});

it('returns null on a plate-free XML alert', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <EventNotificationAlert version="2.0">
        <eventType>heartbeat</eventType>
        <eventState>inactive</eventState>
    </EventNotificationAlert>
    XML;

    $event = app(HikvisionWebhookParser::class)->parse($xml, 'application/xml');

    expect($event)->toBeNull();
});

it('returns null when the body is empty', function () {
    expect(app(HikvisionWebhookParser::class)->parse('', 'multipart/form-data; boundary=x'))->toBeNull();
});

it('returns null when there is no boundary in the content type', function () {
    $event = app(HikvisionWebhookParser::class)->parse(
        hikMultipart(hikXml()),
        'multipart/form-data',
    );

    expect($event)->toBeNull();
});

it('does not fall over on an unknown Content-Type on the XML part', function () {
    // Some firmware labels the XML part as application/octet-stream. As long
    // as the filename or body signals XML, we still want to accept it.
    $body = "--BOUND\r\n"
        .'Content-Disposition: form-data; name="anpr.xml"; filename="anpr.xml"'."\r\n"
        .'Content-Type: application/octet-stream'."\r\n\r\n"
        .hikXml(plate: 'JD45GP')."\r\n"
        .'--BOUND--'."\r\n";

    $event = app(HikvisionWebhookParser::class)->parse(
        $body,
        'multipart/form-data; boundary=BOUND',
    );

    expect($event)->not->toBeNull()
        ->and($event->capture->plateNumber)->toBe('JD45GP');
});

it('extracts extension from content type for use in storage paths', function () {
    $body = hikMultipart(
        xml: hikXml(),
        images: [['content_type' => 'image/png', 'bytes' => 'png-bytes']],
    );

    $event = app(HikvisionWebhookParser::class)->parse(
        $body,
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    );

    expect($event->attachments[0]->extensionFromContentType())->toBe('png');
});
