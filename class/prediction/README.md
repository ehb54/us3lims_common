# Runtime prediction advisory

Advisory only. It records what it would have recommended for a job's walltime
and never changes what is submitted. The scheduler directive is whatever
`resolveWalltime()` produced, with the advisory on or off.

It exists for one evaluation window and is meant to be removed afterwards.

## What is installed

| Item | Where |
| --- | --- |
| The code | this directory, inside `web/common` |
| The call site | one statement in `submit_slurm::submit()`, after sbatch has run and `update_db()` has returned true |
| The models | one JSON artifact per method family, by default `/home/us3/lims/etc/runtime_advisory_<family>.json` |
| The records | `gfac.runtime_prediction`, created from `runtime_pilot_table.sql` |
| The switch | `$global_runtime_advisory_enabled` in `global_config.php` |

Families served: `2DSA`, `GA`, `PCSA`. A request whose method begins with one of
those names belongs to that family, so `2DSA-CG` is 2DSA and `GA-MC` is GA.
`DMGA` belongs to none and is not served.

## Turning it on

1. Create the table:

       mysql gfac < runtime_pilot_table.sql

   The statement is `CREATE TABLE IF NOT EXISTS`, so re-running it leaves
   existing records alone.

2. Install the artifacts for the families this host should serve, readable by
   the web user:

       /home/us3/lims/etc/runtime_advisory_2DSA.json
       /home/us3/lims/etc/runtime_advisory_GA.json
       /home/us3/lims/etc/runtime_advisory_PCSA.json

   A family with no artifact installed is simply not served, and its
   submissions record `unsupported`. That is how a family is left out.

3. Set the switch:

       $global_runtime_advisory_enabled = true;

Nothing else is required. Paths are only configured where a host keeps the
files elsewhere, with `$global_runtime_advisory_artifact` as an array keyed by
family.

### When this host's cluster name isn't one the model recognizes

The model's cluster input is keyed on the historical identities in
`runtime_cluster_map.php` (real site hostnames). A co-located or renamed
cluster entry -- `us3iab-node0` on an appliance, a dev stack's own name --
almost never matches one of those, so every submission to it reads
`unsupported` by default, regardless of whether the model could otherwise
have scored it.

Set `prediction_cluster_name` on that cluster's entry in `$cluster_details`
to tell the advisory which identity to score the request as, without
changing where or how the job is actually submitted:

    $cluster_details['us3iab-node0']['prediction_cluster_name']
        = 'demeler9.uleth.ca';

This affects only the prediction: the submission destination, SSH target,
queue, Slurm directives and walltime are all unchanged, and every record
keeps both the real destination and the identity actually scored (and
whether it came from this setting or from the entry's own name), so the two
are never confused in the data. An identity that is unknown, or that this
host's installed model has no indicator for, still records `unsupported` --
this setting does not make every request scoreable, only the ones that can
legitimately be regarded as going to a cluster the model knows.

On a validation VM, a deliberately supported identity exercises a real
`ok` prediction end to end so the pipeline itself is tested. That is a test
mapping, not a demonstration of prediction accuracy on VM hardware, and it
should say so wherever it is documented. A production mapping should name
the identity that actually represents the hardware a job runs on.

## Turning it off

Set `$global_runtime_advisory_enabled = false;`. With it off a submission reads
that one setting and does nothing further: no file is opened and no row is
written. The setting is per-host and survives a deploy, which commenting out
the call site would not.

## Removing it

    DROP TABLE IF EXISTS gfac.runtime_prediction;

That statement is at the bottom of `runtime_pilot_table.sql` so removal is not a
reconstruction job. Collect the records first; they cannot be rebuilt.

## What a record means

`status` says what happened, and every submission the hook saw produces a row:

| status | meaning |
| --- | --- |
| `ok` | a recommendation was produced |
| `unsupported` | not served: another family, no artifact for this family, or a destination with no cluster code |
| `input_error` | an operand the model was fitted with was absent or not a value it was fitted with |
| `artifact_error` | the artifact for this family could not be read, or holds another family's model |
| `record_error` | everything worked and the row could not be written |
| `advisory_error` | an unexpected failure, caught |

A row that is not `ok` is still a row. A window with no records would otherwise
be indistinguishable from a window with no jobs.

## Operating notes

- A submission is never failed by the advisory. Every failure inside it is
  caught, recorded if it can be, and swallowed.
- Each family's artifact is read once per process, so the file is not re-read
  per submission. Replacing an artifact while the window is open changes the
  recorded content hash, which the readout reports as more than one model for
  that family rather than averaging over it.
- Two database reads per submission, both on the instance database: the edited
  data row for a dataset and the raw data header it refers to.
- No request XML, no researcher identity and no scientific result content is
  recorded. The stored feature vector holds numbers, category codes and nulls.
