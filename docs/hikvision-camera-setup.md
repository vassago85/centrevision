# Hikvision camera setup — HTTP Listening webhook

This walks you through configuring a Hikvision ANPR camera to POST plate events
to CentreVision over HTTPS, so the camera dials out over the customer's normal
internet connection and does not need a VPN, port forwarding, or an FTP server
at either end.

Verified on:

- `iDS-2CD7A46G0/P-IZHS` (DeepinView 4 MP ANPR bullet)
- Firmware `V5.7.x` and newer

Similar Hikvision models with "HTTP Listening" support (most DeepinView and
DeepinMind ANPR cameras) follow the same steps; menu labels vary slightly.

## Prerequisites

- Camera is on the LAN and you can log into its web UI as `admin`.
- CentreVision has the camera created in `Cameras → Add camera` with
  **Ingestion mode = Webhook**. You know its:
  - Webhook URL (e.g. `https://api.centrevision.co.za/webhooks/hik/12`)
  - HTTP Basic username (the numeric camera id, e.g. `12`)
  - HTTP Basic password (the per-camera secret)
- The customer site has outbound HTTPS to the CentreVision domain. Nothing
  needs to be *forwarded in*; the camera makes the connection.

The **Setup** button on the Cameras page opens a modal with the URL and secret
already filled in, so this doc is mainly the mirror-image reference for the
camera-side clicks.

## 1. Register CentreVision as the HTTP Listening host

Configuration → **Network** → **Advanced Settings** → **HTTP Listening**

Fill in exactly:

| Field                | Value                                                     |
|----------------------|-----------------------------------------------------------|
| Enable HTTP Listening| On                                                        |
| Destination IP / URL | The full URL from the setup modal (`https://...`)         |
| Protocol             | `HTTP` (the reverse proxy terminates TLS for us)          |
| HTTP Method          | `POST`                                                    |
| Port                 | Leave as `80` — it is ignored when the URL is a full URL  |
| Data Type            | `XML`                                                     |
| User Name            | The camera id shown in the setup modal (the number)       |
| Password             | The secret shown in the setup modal                       |

Newer firmware exposes only a URL field; older firmware asks for `Destination
IP`, `Port`, and `URL` separately. In the latter case, set the URL to just the
path (`/webhooks/hik/12`), the IP to the public CentreVision hostname, and
port to `443`.

Save. The camera will not test the endpoint here; verification happens once an
event fires.

## 2. Route ANPR events to that host

Configuration → **Event** → **Smart Event** → **Road Traffic** →
**Vehicle Detection**

Open the **Linkage Method** tab. Tick:

- [x] **Notify Surveillance Center** *(usually pre-checked; leave it)*
- [x] **HTTP Listening** *(this is the one that matters)*

Do **not** disable the other linkages the customer already has (recording,
alarm-out, on-camera storage, etc.); we are only *adding* an outbound HTTP
push. Save.

If the camera has separate detection rules per lane, tick "HTTP Listening" on
every rule that should feed into CentreVision.

## 3. Verify

Drive a vehicle past. Within a couple of seconds:

- The Cameras page in CentreVision shows the camera as **Online** and its
  **Last event** timestamp updates.
- A row appears in `plate_events` for that camera.
- If you tail the app log, no `Unparseable Hikvision webhook payload` entry
  appears.

If nothing arrives, work down the failure ladder in the next section.

## Troubleshooting

**No events at all.**

- Confirm outbound HTTPS to the CentreVision hostname is not blocked at the
  customer firewall.
- On the camera, `Configuration → Maintenance → Log`: filter to `Network`
  events; a failed POST logs the HTTP response code.
- `curl -u {cameraId}:{secret} https://api.centrevision.co.za/webhooks/hik/{cameraId}`
  from any laptop on the customer network should return `200 OK`. `401` means
  the credentials are wrong or the camera is inactive. `429` means the rate
  limiter tripped (unlikely under normal traffic).

**Camera is online but `plate_events` stays empty.**

- The Linkage Method tick did not save. Reopen Vehicle Detection → Linkage
  Method and confirm HTTP Listening is still ticked.
- The camera is sending heartbeats but not vehicle events. Check
  `Configuration → Event → Smart Event → Road Traffic → Detection
  Configuration`: the detection rule must be enabled with a valid area drawn.

**`401 Unauthorized` in the camera log.**

- The Basic username must be the numeric camera id, not the camera's *name*.
- The secret is per-camera; two cameras cannot share one. If in doubt, click
  **Regenerate secret** on the Cameras page and paste the new value into the
  camera.

**`Mixed content` / `SSL_ERROR` in the camera log.**

- The Destination URL must be `https://…` when going through the reverse
  proxy. `http://` will be redirected, which some Hikvision firmware handles
  and some does not.

## Smoke-test the endpoint without a camera

Once the app is deployed, you can validate the entire webhook path from any
machine before you touch the camera. Substitute the values from the setup
modal into this one-liner:

```bash
CAM_ID=12
SECRET='paste-secret-here'
HOST='https://api.centrevision.co.za'

curl -i -u "$CAM_ID:$SECRET" \
  -H 'Content-Type: application/xml' \
  --data-binary @- \
  "$HOST/webhooks/hik/$CAM_ID" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<EventNotificationAlert version="2.0">
    <ipAddress>10.0.0.25</ipAddress>
    <channelID>1</channelID>
    <dateTime>2026-08-03T14:32:15+02:00</dateTime>
    <eventType>ANPR</eventType>
    <eventState>active</eventState>
    <ANPR>
        <country>ZA</country>
        <licensePlate>TEST01GP</licensePlate>
        <line>1</line>
        <direction>forward</direction>
        <confidenceLevel>92</confidenceLevel>
        <plateType>unknown</plateType>
    </ANPR>
</EventNotificationAlert>
XML
```

You should get `HTTP/1.1 200 OK` and see `TEST01GP` land in `plate_events`
within a second (once the queue worker picks it up). If you see `401`, the
Basic credentials are wrong; if you see `429`, you have hit the rate limit
(60/sec/camera by default).

## Notes for developers

- The webhook parser also accepts a bare XML body (no `multipart/form-data`
  wrapper), so an older firmware that predates the multipart-with-images
  convention still works. Attachments will simply be absent.
- Dedupe against the ISAPI stream and the FTP drop path is automatic: running
  webhook + one of the fallback paths on the same camera is safe.
- On terminal parse failure, the raw body is moved to
  `storage/app/private/hikvision-webhook-quarantine/{camera_id}/` for
  post-mortem. Nothing that contains a plate string is written to the app log.
