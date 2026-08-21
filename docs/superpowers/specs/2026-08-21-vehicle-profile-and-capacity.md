# Vehicle profile + parking capacity

**Date:** 2026-08-21  
**Shipped with:** security alert email effort  
**API:** skipped

## Vehicle profile (#2)

- Route: `activity/plates/{plate}` (`vehicle`) for owner admin + security operator
- Page: visit history (50), watchlist status, freeform `plate_notes`
- Plate numbers on Security/Activity link through when the user can `viewSecurity`

## Parking capacity (#4)

- Site setting `parking_capacity` (Settings → Thresholds)
- `Site::parkingCapacity()` / `TrafficAnalytics::occupancyPercent()`
- Today dashboard third card becomes **Occupancy** when capacity is set (shows `on site / bays`)
