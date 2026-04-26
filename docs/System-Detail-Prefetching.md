# System Detail Prefetching

This note documents why the frontend can accidentally fan out into many
`/api/systems/{slug}` requests, and why those requests can reach EDSM.

The ED:CS web app is a separate Next.js app in `edcs-app/`, but its route
prefetch behaviour can have backend side effects in this API.

## Request Chain

Next.js `<Link>` prefetches visible routes in production unless prefetching is
disabled for that link.

For system detail links, that means a visible link such as:

```tsx
<Link href={`/systems/${system.slug}`}>
```

can pre-render the frontend route:

```text
edcs-app/app/systems/[slug]/page.tsx
```

That page fetches the full system detail payload:

```text
GET /api/systems/{slug}?withInformation=1&withBodies=1&withStations=1&withFleetCarriers=1
```

On the API side, `SystemController::show()` caches detail responses for five
minutes. On a cache miss:

- missing systems may be fetched from EDSM via `EdsmSystemService`
- missing body data may be fetched from EDSM via `EdsmSystemBodyService`
- missing information may be fetched from EDSM via `EdsmSystemInformationService`
- stations and fleet carriers are refreshed from EDSM whenever either
  `withStations=1` or `withFleetCarriers=1` is requested on a detail cache miss

The last point is the expensive one for ordinary system-link prefetches. Fleet
carriers are mobile, so the API deliberately refreshes station/fleet-carrier
state on detail cache misses when those relations are requested.

## Current Policy

Do not allow automatic Next.js prefetch on high-density lists of links to
`/systems/{slug}`.

High-density lists include tables, search results, route results, market
results, and sidebars that may render many system links at once. Those links
should use:

```tsx
<Link prefetch={false} href={`/systems/${slug}`}>
```

This preserves normal navigation behaviour while avoiding background requests
that look like real system detail visits to the API.

## Frontend Areas Currently Disabled

These frontend links currently use `prefetch={false}` because they can render
multiple system detail links at once:

| Area | File |
|------|------|
| Star systems table | `edcs-app/app/systems/components/systems-table.tsx` |
| Distance search list | `edcs-app/app/distance-search/components/distance-results-list.tsx` |
| Distance search 3D selected system panel | `edcs-app/app/distance-search/components/distance-results-3d.tsx` |
| Route plotter jump list | `edcs-app/app/route-plotter/components/route-jump-list.tsx` |
| Market trade-route system links | `edcs-app/app/market-search/components/trade-route-list.tsx` |
| Market commodity listing system links | `edcs-app/app/market-search/components/commodity-listing-table.tsx` |
| Recent systems sidebar | `edcs-app/components/sidebar/sidebar-recent-systems.tsx` |

## Frontend Areas Intentionally Left Alone

Some system links were not changed because they are not high-density fan-out
surfaces, or they point to nested routes rather than directly to system detail:

| Area | Reason |
|------|--------|
| System/body breadcrumbs | Single navigation links on already-focused detail pages |
| Body and star table links | Point to `/systems/{slug}/body/{bodySlug}`, not directly to system detail |
| Solar map links | Point to `/systems/{slug}/solar-map` or back to one already-focused system |
| Station detail system link | Usually one link on one focused station detail page |
| Latest system card | Single prominent link, not a table/list fan-out |

Revisit these if their pages begin rendering many visible system-detail links.

## Backend Areas Involved

| File | Role |
|------|------|
| `app/Http/Controllers/SystemController.php` | Handles `/api/systems` and `/api/systems/{slug}`; detail requests can refresh EDSM-backed relations on cache miss |
| `app/Services/Edsm/EdsmSystemService.php` | Fetches a missing system from EDSM |
| `app/Services/Edsm/EdsmSystemBodyService.php` | Fetches missing body data from EDSM |
| `app/Services/Edsm/EdsmSystemInformationService.php` | Fetches missing information data from EDSM |
| `app/Services/Edsm/EdsmSystemStationService.php` | Refreshes stations and fleet carriers from EDSM |

## Changing This Later

If automatic prefetching is re-enabled for any high-density system link, check
the backend behaviour first. Options include:

- keep `prefetch={false}` on list/table links and only prefetch deliberate
  single-card links
- split the frontend system detail fetch into a lightweight prefetch-safe
  summary request and a user-navigation-only full detail request
- avoid requesting `withStations=1` and `withFleetCarriers=1` during metadata
  or route prefetch work
- add a backend mode that loads cached station/fleet-carrier data without
  refreshing EDSM
- move station/fleet-carrier refreshes behind explicit user action or a queued
  freshness job

When testing this behaviour, use a production Next.js build. Development mode
does not always exercise the same prefetch behaviour as production.
