# Setting up a metascheduler pool

A pool is one destination on the submit page that stands for several clusters.
The dispatch worker, part of `submitctl.php`, chooses a member for each request.
A pool is off until a site adds one. Choosing a named cluster never uses a pool.

The names below (`campus`, `lab`, `center`, `pool`) are examples. Keep accounts,
logins and work directories out of public files.

## Getting started

A pool of one local cluster, for a site that has `campus` working by name:

1. `php <instance>/tools/check_installation.php --deep` passes its "Dispatch
   service" section.
2. In `global_config.php`, outside the `uslims_upgrade.php` block:

   ```php
   $cluster_details[ 'campus' ][ 'tier' ] = 'local';
   $cluster_details[ 'pool' ] = [
       'active'   => true,
       'name'     => 'pool',
       'clusters' => [ 'campus' ],
   ];
   ```

   Set the tier in the `--cluster-values` file instead when that file manages
   `campus`.
3. In the instance's `$cluster_configuration`, add `'pool' => [ 'active' => true ]`.
   `campus` must be active there too.
4. Add `pool` and `campus` to your own account's cluster authorizations.
5. `php ~us3/lims/bin/dispatch_policy_check.php` reports `pool: ACTIVE`.
6. Log in again, submit one job to `pool`, and follow it on the queue page.

To stop, set the pool's `'active'` to false. The sections below explain each step
and the larger configurations.

## 1. Check that the dispatch service is ready

    php <instance>/tools/check_installation.php --deep

The "Dispatch service" section must pass: the dispatch tables in `gfac` and in
every instance database, a recent worker heartbeat and no urgent alerts. A host
upgraded by hand also needs the heartbeat line in `/etc/crontab`, which
`uslims_upgrade.php` prints at the end of its run.

While the tables are missing, the submit pages refuse the pool and submit
nothing.

## 2. Prepare the members

Each member is a Slurm entry in `global_config.php` that already works when it
is chosen by name. It also needs:

- `'active' => true`.
- `'tier' => 'local'` or `'national'`. Any other value, or none, makes it
  ineligible. With a version of `uslims_upgrade.php` whose `--cluster-values`
  accepts `tier`, set it in that file; do not edit an entry the file manages.
- Its own destination. Two members with the same `controller` (default: `name`)
  and `queue` are one destination, and the pool stays inactive. A `-gamc` entry
  on the same host and queue as its plain entry is the usual case.

Each member must also be active in the instance's cluster list, step 4.

## 3. Add the pool entry

Add it to `global_config.php` by hand, **outside** the block between
`## BEGIN uslims_upgrade.php settings` and `## END uslims_upgrade.php settings`,
which the upgrade rewrites. Put it after `$cluster_details` is defined.

Start with `'active' => false`, as in these examples, and turn it on in step 5.
Each one is a complete entry.

**One local cluster**

```php
$cluster_details[ 'pool' ] = [
    'active'   => false,
    'name'     => 'pool',
    'clusters' => [ 'campus' ],
];
```

**Round robin**: each request goes to the next member. With no `dispatch` key this
is already what local members do; national members are listed as overflow and not
used.

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

**Weighted round robin**: of every four requests, three go to `campus` and one to
`lab`.

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

**Ordered**: `campus` whenever it can take the job, else `lab`.

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

**Overflow**: `center` is used when `campus` cannot start the job within ten
minutes, or cannot take it at all. Jobs sent there use its allocation.

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

**National only**: the primary group has to be named.

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

| Setting | Meaning |
| --- | --- |
| `strategy` | `ordered`: the first member that can take the job. `round_robin`: in turn. `weighted_round_robin`: in turn by `weights`, one positive integer per member. |
| `overflow` | Used only with `'enabled' => true`. With `queue` admission, only when no primary member can take the job. Overflow to a national member spends its allocation. |
| `admission` | `queue` (default): use normal Slurm queueing. `start_within_allowance`: prefer a member that can start within `wait_allowance_seconds`, then overflow. |
| `fallback` | When no member can start in time and overflow does not take the job: `queue_preferred` queues it at a primary member, `wait_for_dispatch` holds it and tries again. |
| `overflow_on_assessment_unknown` | Whether overflow may be used when a member's queue could not be read. Default false. |

A member may not be in both groups.

Parallel (GA-MC) jobs are offered only entries with `'pmg' => true`, and an entry
with `'pmgonly' => true` is offered only those jobs. Make a separate pool for them:

```php
$cluster_details[ 'pool-gamc' ] = [
    'active'   => false,
    'name'     => 'pool-gamc',
    'clusters' => [ 'campus-gamc' ],
    'pmg'      => true,
    'pmgonly'  => true,
];
```

## 4. Allow it in the instance and for accounts

- **Instance:** add the pool and every member, with `'active' => true`, to the
  instance's `$cluster_configuration`: `cluster_config.php` in the instance, else
  in the site directory, else in `uslims3_newlims`. This is not gridctl's
  `cluster_config.php`, which holds the status probes. A pool needs no probe.
- **Accounts:** level 2 or above, with the pool **and** its members in the
  account's cluster authorizations. Being allowed to use the pool does not allow
  its members. A member marked `'localhost' => true` needs no entry.

The status the page shows comes from the members, so a new pool can look down
until `cluster_status.php` has probed them.

## 5. Check the policy, then turn it on

    php ~us3/lims/bin/dispatch_policy_check.php

It prints each pool's policy, its ineligible members and the reasons it is
inactive. With `'active' => false` the only reason should be
`metascheduler 'pool' is disabled (active=false)`.

Set the pool's `'active' => true` and run it again. It must report `ACTIVE`; it
exits 1 when an active pool cannot dispatch. The worker and the pages read the
change by themselves; no restart is needed.

## 6. Test it

1. Submit one job to the pool. The page says "Analysis received", and the queue
   page then shows the cluster and the job number.
2. Open the Dispatch Dashboard (administrators, Status Monitor menu). It shows
   the chosen member and why the others were skipped.
3. For an autoflow site, run one stage with the pool as its cluster.

A job sent to a national member uses its allocation.

## Turning it off

Set the pool's `'active'` to false. New requests are refused with
`metascheduler 'pool' is disabled (active=false)`. Requests still waiting fail
with the same reason, so users can submit to a cluster they choose. A request
already being submitted, and every accepted job, continues.

To stop using one member, set that member inactive, or list it in
`down_clusters.php` to hold new work from it.

## When a request does not move

| State | What to do |
| --- | --- |
| Waiting | The reason is on the queue page and the dashboard. It expires after `$global_dispatch_max_wait_seconds`. |
| Blocked | Correct the cause, then use Retry on the dashboard. |
| Submission unknown | The job may be running. Do not resubmit; resolve it on the dashboard with evidence from the cluster. |
| Nothing happens | Check `submitctl.php` and its log, and the heartbeat on the dashboard. |
