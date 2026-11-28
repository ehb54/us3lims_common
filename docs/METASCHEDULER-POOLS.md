# Metascheduler pools

Let users submit to a group of clusters instead of choosing one. The dispatch
worker picks a cluster for each request, by rules you set.

## Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
  - [Members](#members)
  - [Pool entry](#pool-entry)
  - [Dispatch settings](#dispatch-settings)
  - [Instances and accounts](#instances-and-accounts)
- [Examples](#examples)
- [Usage](#usage)
  - [Check the policy](#check-the-policy)
  - [Test a pool](#test-a-pool)
  - [Turn a pool off](#turn-a-pool-off)
- [Troubleshooting](#troubleshooting)
- [Related files](#related-files)

## Overview

A pool is one destination on the submit page that stands for several clusters.
A request to it is recorded, and the dispatch worker (part of `submitctl.php`)
chooses a member, submits the job and follows it. Choosing a named cluster
submits directly and never uses a pool.

No pool exists until a site adds one.

The names `campus`, `lab`, `center` and `pool` below are examples. Keep accounts,
logins and work directories out of public files.

## Features

| Feature | Use it to |
| --- | --- |
| Round robin | share requests evenly |
| Weighted round robin | send more requests to larger clusters |
| Ordered | always prefer one cluster |
| Overflow | use a second group only when the first cannot take or start the job |
| Waiting | hold a request until a cluster can start it |

## Requirements

- LIMS 4.3.0 or later, with `submitctl.php` running.
- The dispatch tables and worker in order. The "Dispatch service" section of this
  check must pass:

  ```sh
  php <instance>/tools/check_installation.php --deep
  ```

- The heartbeat check in cron. A host upgraded by hand needs the line that
  `uslims_upgrade.php` prints at the end of its run.
- At least one Slurm cluster that already works when it is chosen by name.

While the tables are missing, the submit pages refuse a pool and submit nothing.

## Quick start

A pool of one local cluster, `campus`.

1. In `global_config.php`, after `$cluster_details` and outside the block between
   `## BEGIN uslims_upgrade.php settings` and `## END uslims_upgrade.php settings`:

   ```php
   $cluster_details[ 'campus' ][ 'tier' ] = 'local';
   $cluster_details[ 'pool' ] = [
       'active'   => true,
       'name'     => 'pool',
       'clusters' => [ 'campus' ],
   ];
   ```

   If a `--cluster-values` file manages `campus`, set its tier there instead.

2. In the instance's `$cluster_configuration`, add `'pool' => [ 'active' => true ]`.
   `campus` must be active there too.
3. Add `pool` and `campus` to your account's cluster authorizations.
4. Check the policy. It must report `pool: ACTIVE`:

   ```sh
   php ~us3/lims/bin/dispatch_policy_check.php
   ```

5. Log in again, submit one job to `pool`, and follow it on the queue page.

To undo it, set the pool's `'active'` to false.

## Configuration

### Members

Each member is a Slurm entry in `$cluster_details` with:

| Key | Value |
| --- | --- |
| `active` | `true` |
| `tier` | `'local'` or `'national'`. Any other value, or none, makes it ineligible. |
| `controller` | Optional. The scheduler it belongs to; default is `name`. |

Two members with the same `controller` and `queue` are one destination, and the
pool stays inactive. A `-gamc` entry on the host and queue of its plain entry is
the usual case.

Do not edit an entry that a `--cluster-values` file manages. Set `tier` in that
file, with a version of `uslims_upgrade.php` that accepts it.

### Pool entry

Add it to `global_config.php` by hand, outside the `uslims_upgrade.php` block,
which the upgrade rewrites.

| Key | Required | Meaning |
| --- | --- | --- |
| `active` | yes | `false` refuses new requests |
| `name` | yes | the name shown to users |
| `clusters` | yes | the members, as a list |
| `dispatch` | no | see below |
| `pmg` | no | `true` offers the pool for parallel (GA-MC) jobs |
| `pmgonly` | no | `true` offers it only for those jobs |

Without `dispatch`, local members take turns and national members are listed as
overflow but not used. A pool with only national members needs `dispatch`.

### Dispatch settings

| Key | Default | Meaning |
| --- | --- | --- |
| `primary` | required | the preferred group: `members`, `strategy`, `weights` |
| `overflow` | none | a second group, used only with `'enabled' => true` |
| `admission` | `queue` | `queue`: use normal Slurm queueing. `start_within_allowance`: prefer a member that can start in time. |
| `wait_allowance_seconds` | `0` | how soon "in time" is |
| `fallback` | see below | when no member can start in time and overflow does not take the job: `queue_preferred` queues it at a primary member, `wait_for_dispatch` holds it and tries again |
| `overflow_on_assessment_unknown` | `false` | whether overflow may be used when a primary member's queue could not be read |

| Strategy | Choice |
| --- | --- |
| `ordered` (default) | the first member that can take the job |
| `round_robin` | each member in turn |
| `weighted_round_robin` | in turn by `weights`, one positive integer per member |

- With `queue` admission, overflow is used only when no primary member can take
  the job.
- `fallback` is `wait_for_dispatch` by default for a rotating primary group with
  `start_within_allowance` and no overflow, else `queue_preferred`.
- A member may be in one group only.
- A job sent to a national member uses its allocation.

### Instances and accounts

- **Instance:** the pool and every member must be in the instance's
  `$cluster_configuration` with `'active' => true`. That is `cluster_config.php`
  in the instance, else in the site directory, else in `uslims3_newlims`. It is
  not gridctl's `cluster_config.php`, which holds the status probes. A pool needs
  no probe.
- **Account:** level 2 or above, with the pool **and** its members in the
  account's cluster authorizations. A member marked `'localhost' => true` needs no
  entry.

## Examples

Each is a complete entry, off until you set `'active' => true`.

### One cluster

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus' ],
];
```

### Round robin

Each request goes to the next member.

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus', 'lab' ],
    'dispatch' => [
        'primary' => [ 'members' => [ 'campus', 'lab' ], 'strategy' => 'round_robin' ],
    ],
];
```

### Weighted round robin

Of every four requests, three go to `campus` and one to `lab`, while both can take
work.

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus', 'lab' ],
    'dispatch' => [
        'primary' => [ 'members'  => [ 'campus', 'lab' ],
                       'strategy' => 'weighted_round_robin',
                       'weights'  => [ 'campus' => 3, 'lab' => 1 ] ],
    ],
];
```

### Ordered

`campus` whenever it can take the job, else `lab`.

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus', 'lab' ],
    'dispatch' => [
        'primary' => [ 'members' => [ 'campus', 'lab' ], 'strategy' => 'ordered' ],
    ],
];
```

### Overflow

`center` is used when `campus` cannot start the job within ten minutes, or cannot
take it at all.

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus', 'center' ],
    'dispatch' => [
        'primary'   => [ 'members' => [ 'campus' ], 'strategy' => 'ordered' ],
        'overflow'  => [ 'members' => [ 'center' ], 'strategy' => 'ordered', 'enabled' => true ],
        'admission' => 'start_within_allowance',
        'wait_allowance_seconds' => 600,
    ],
];
```

### Waiting instead of queueing

A request waits in the LIMS until `campus` or `lab` can start it within ten
minutes.

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus', 'lab' ],
    'dispatch' => [
        'primary'   => [ 'members' => [ 'campus', 'lab' ], 'strategy' => 'round_robin' ],
        'admission' => 'start_within_allowance',
        'wait_allowance_seconds' => 600,
        'fallback'  => 'wait_for_dispatch',
    ],
];
```

### National only

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'center' ],
    'dispatch' => [
        'primary' => [ 'members' => [ 'center' ], 'strategy' => 'ordered' ],
    ],
];
```

### Parallel (GA-MC) jobs

```php
$cluster_details[ 'pool-gamc' ] = [
    'active'   => false,
    'name'     => 'pool-gamc',
    'clusters' => [ 'campus-gamc' ],
    'pmg'      => true,
    'pmgonly'  => true,
];
```

## Usage

### Check the policy

```sh
php ~us3/lims/bin/dispatch_policy_check.php
```

It prints each pool's policy, its ineligible members and why it is inactive, and
exits 1 when a pool set active cannot dispatch. For a pool that is only switched
off, the one reason is `metascheduler 'pool' is disabled (active=false)`.

The worker and the pages read a change by themselves. No restart is needed.

### Test a pool

1. Submit one job to it. The page says "Analysis received", and the queue page then
   shows the cluster and the job number.
2. Open the Dispatch Dashboard (administrators, Status Monitor menu). It shows the
   chosen member and why the others were skipped.
3. For an autoflow site, run one stage with the pool as its cluster.

### Turn a pool off

Set its `'active'` to false.

- New requests are refused: `metascheduler 'pool' is disabled (active=false)`.
- Waiting requests fail with the same reason, so users can choose a cluster.
- Requests already being submitted, and accepted jobs, continue.

To stop using one member, set it inactive, or list it in `down_clusters.php` to
hold new work from it.

## Troubleshooting

| Problem | What to do |
| --- | --- |
| The pool is not listed | Check the instance's `$cluster_configuration`, the account's authorizations, and for GA-MC jobs `pmg`. |
| The pool is listed as down | Its status comes from its members. Check `cluster_status.php`, and that the account may use the members. |
| `tier not configured` | Set `tier` on the member. |
| `aliases of one destination` | Remove one of the two members. |
| `national-only pool` | Add `dispatch` with a `primary` group. |
| "not available on this server right now" | The dispatch tables are missing or `gfac` cannot be reached. Run `check_installation.php --deep`. |
| A request waits | The queue page and the dashboard give the reason. It expires after `$global_dispatch_max_wait_seconds`. |
| A request is blocked | Correct the cause, then use Retry on the dashboard. |
| Submission unknown | The job may be running. Do not resubmit. Resolve it on the dashboard with evidence from the cluster. |
| Nothing happens | Check `submitctl.php`, its log, and the heartbeat on the dashboard. |

## Related files

| File | Repository |
| --- | --- |
| `global_config.php.template`: every setting and its default | us3lims_common |
| `dispatch_policy_check.php`: the policy check | us3lims_gridctl |
| `tools/check_installation.php`, `CONFIGURATION.md` | us3lims_dbinst |
| `uslims_upgrade.php` and its README | us3lims_dbutils |
