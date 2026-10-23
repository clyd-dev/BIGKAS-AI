# Batch assessment — the checks a person has to do by hand

The automated suite (707 tests) covers every rule in the batch flow, but it
cannot do four things: run real Whisper on real audio, measure how long that
takes, close a browser, or look at a screen. This is that part.

Do it **before** turning `BATCH_ASSESSMENT_ENABLED=true` on for a real class.
Budget about two hours, most of it waiting — Sections A and B run themselves
once started, so start A, then do C while it works.

## Before you start

1. `BATCH_ASSESSMENT_ENABLED=true` in `.env`, then
   `php artisan optimize:clear && php artisan optimize`.
   *Expect:* `/assessments/batch/create` loads. *If it 404s:* the config cache
   was not rebuilt — the flag is still off as far as the app is concerned.
2. `crontab -l | grep schedule:run` — the line must be there.
   *If it is missing:* stop. Nothing will ever be scored and every check below
   will "fail" for the wrong reason.
3. `php artisan schedule:clear-cache` once, to be sure no stale overlap lock is
   held from an earlier killed run.
3b. **Confirm something is actually driving the lane before you start.** This
   has already cost one whole test run: a batch was sent, every card sat at
   `queued` for ever, and it looked exactly like a broken feature. Nothing was
   broken — nothing was running the scheduler.
   - On a server: the crontab line from step 2 is what drives it.
   - **On Windows, or anywhere locally, there is no cron.** Open a second
     terminal and leave `php artisan schedule:work` running for the whole
     session (or call `php artisan assessments:rescore` by hand each time you
     want the lane to move). Check it is working: `Rescored assessment` lines
     appear in the log within a minute of sending.
   The board will now tell you itself — after about a quarter of an hour with
   nothing scored it shows "Nothing has been scored for N minutes… the analysis
   scheduler may not be running". If you see that banner during these checks,
   it is this step you skipped, not a bug in the batch flow.
4. Have the log open in another window:
   `tail -f storage/logs/laravel.log | grep -i 'rescor\|whisper\|error'`
5. 15 children, or 15 learner records and 15 recordings you can make yourself.
   Real audio — a silent or 2-second file measures nothing.

---

## Section A — the measured 15-child run

1. **Set up a batch of 15.** `/assessments/batch/create`, pick a class, pick a
   passage, select 15 learners, create.
   *Expect:* the board opens with 15 cards, all `waiting`.
   *If fewer cards than learners selected:* the roster builder rejected some —
   read the message it gave you, it names why.

2. **Record all 15.** Open each card, record the child reading, save. Do not
   send yet.
   *Expect:* each card moves to `recorded` with a Send action.

3. **Note the clock, then send everything.** Write down the wall-clock time of
   the **last send** (press Send all ready, or send the last card by hand).
   This is your T₀.
   *Expect:* every card moves to `queued`, and the board shows an
   "about N minutes" estimate.

4. **Walk away.** Come back when the board says it is done.
   *Expect:* every card ends at `done` or `needs verdict`; the batch status
   reads `completed`.
   *If some cards are still `queued` after 90 minutes with a healthy engine:*
   check the log for `Rescored assessment` lines. None at all means a held
   overlap lock — see `schedule:clear-cache` in the deploy notes.

5. **Write down T₁** — the wall-clock time of the **last** result appearing.

6. **Record the three numbers.**

   ```bash
   php artisan tinker --execute='
     $b = \App\Models\AssessmentBatch::latest("id")->first();
     $ms = \App\Models\Assessment::where("batch_id", $b->id)
         ->whereNotNull("analysis_duration_ms")->pluck("analysis_duration_ms")->sort()->values();
     printf("timed: %d of %d\n", $ms->count(), $b->entries()->count());
     printf("median: %.1f s\n", $ms[intdiv($ms->count(),2)] / 1000);
     printf("worst:  %.1f s\n", $ms->last() / 1000);
     printf("status: %s\n", $b->status);
   '
   ```

   - **Median per recording** — write it down.
   - **Worst case per recording** — write it down. Section D depends on it.
   - **Wall clock T₁ − T₀** — the spec claims **25–45 minutes** for 15.

   *If T₁ − T₀ is well over 45 minutes:* the spec's figure is wrong for this
   hardware. That is a finding to record, not a bug — but it changes what you
   tell teachers in Section C, step 1.

7. **Did the whole roster clear in one run?**

   ```bash
   grep -c 'Rescored assessment' storage/logs/laravel.log
   ```
   *Expect:* the count rose by exactly 15 across the whole exercise, in one
   unbroken stretch of minutes.
   *If it rose in two separated bursts:* a run hit `--limit=20` and a later tick
   picked up the rest. Harmless at 15, but it means the lane is busier than you
   think — something else was queued ahead.

8. **Was any recording scored twice?** `assessment_results.assessment_id` is
   unique, so a second scoring cannot add a row — it **overwrites** the first
   result. So look for the scoring, not the row:

   ```bash
   # the same assessment id rescored more than once
   grep -oE 'Rescored assessment #[0-9]+' storage/logs/laravel.log \
     | sort | uniq -c | awk '$1 > 1'

   # a result written, then written again
   php artisan tinker --execute='
     $b = \App\Models\AssessmentBatch::latest("id")->first();
     foreach ($b->entries()->with("result")->get() as $e) {
       if ($e->result && $e->result->updated_at->gt($e->result->created_at->addSeconds(5))) {
         printf("entry #%d result rewritten at %s (created %s)\n",
           $e->id, $e->result->updated_at, $e->result->created_at);
       }
     }
     echo "checked\n";
   '
   ```
   *Expect:* no output from either but `checked`.
   *If either reports something:* two rescore runs overlapped. The invariant in
   the deploy notes has been breached — stop, and check `--limit` against
   `withoutOverlapping` in `routes/console.php` before using this on a real
   class.

---

## Section B — the close-the-browser proof

This is the requirement the observers in Task 8 exist for. **Watching the board
would hide the failure it is meant to catch**: if the browser's polling is what
drives a batch to `completed`, a watched board looks perfect and an unwatched
one never finishes. The only honest test is not to watch.

1. Set up and record a **small** batch (3 is enough) and send it.
2. **Close the browser entirely.** Not the tab — the whole application. No
   window of this app left open anywhere, on any device.
3. Wait longer than the run should take (median × 3, plus five minutes).
4. **Before opening the board**, ask the database:

   ```bash
   php artisan tinker --execute='
     $b = \App\Models\AssessmentBatch::latest("id")->first();
     printf("status: %s  completed_at: %s\n", $b->status, $b->completed_at);
     foreach ($b->entries as $e) {
       printf("  #%d %s result=%s\n", $e->id, $e->status, $e->result ? "yes" : "NO");
     }
   '
   ```

   *Expect:* every entry `result=yes`, and `status: completed` with a
   `completed_at`.
   *If entries have results but the status is not `completed`:* the work
   happened but nothing noticed it finished — the observer is not firing. That
   is the exact bug this section exists to find.
   *If entries have no results at all:* the lane never ran. Check
   `schedule:run` and the overlap lock first; it is probably not the observer.

5. Only now open the board.
   *Expect:* it already shows the finished state. It should have nothing to do.

---

## Section C — a pass over the real screens

This app is **Bootstrap 5, not Tailwind**. If you take headless screenshots,
shoot at **≥500px wide** — anything narrower invents an overflow bug that does
not exist on a real phone.

1. **The board at 15 cards.** `/assessments/batch/{id}`
   *Expect:* 15 cards readable without horizontal scrolling, at phone width and
   on a laptop. Each card names the child and its state in words, not a colour
   alone. The wait estimate reads as a sentence, not a raw number.

2. **The entry panel.** Open one card.
   *Expect:* the child's name, the passage, the recorder, and — if the card is
   scored — the result. The session code appears as a **reference for your
   records**. It must not invite anyone to type it in anywhere (see Section E).

3. **Remove, on a *scored* card. This is the one that can lose data.**
   Note the child's current reading level first. Then Remove the card.
   *Expect:* the card leaves the board, and
   - the reading is **still in that child's assessment history**;
   - their **reading level is unchanged**;
   - the audio still plays.

   Remove **detaches** a scored reading from the batch. It does **not** delete
   it. *If the reading has vanished from the child's history, or their level
   moved: stop everything and report it.* That is unrecoverable data loss and
   the worst thing this feature could do.

4. **Cancel analysis, on a `queued` card.**
   *Expect:* the card returns to `recorded` with Send available again; the
   recording is untouched; the lane stops owing it a score.
   *If it is refused:* read the message — a card already being transcribed is
   refused on purpose, and that refusal is correct. Try one that is queued but
   not yet started.

5. **Batch Cancel, with a deliberate mix of states.** Build a fresh batch where
   some cards are scored, some queued, and some never recorded. Cancel it.
   *Expect:* the confirmation **names the counts** before you commit. After:
   scored readings stay attached and keep their results; queued cards are
   un-sent; never-recorded cards are removed; the batch reads `cancelled` and
   stays cancelled.
   *If the batch later flips back out of `cancelled`:* report it — a cancelled
   batch must never be revived.

6. **Learner-history delete, including the typed-name gate.** From a child's
   assessment history, delete a **scored** reading.
   *Expect:* a dialog that requires you to **type the child's name exactly**;
   a wrong or empty name refuses; the child's name appears correctly even if it
   contains an apostrophe or `&`. After a correct confirmation the reading and
   its audio are gone and the child's level is recomputed.
   *If the typed-name gate is absent on a scored reading:* report it. A scored
   reading may never be deleted on one click.

---

## Section D — if the measurement disagrees

Compare the **worst case per recording** from Section A, step 6 against
**3 minutes (180 s)**.

- **Worst case ≤ 3 minutes.** Nothing to do. `--limit=20` is correct and
  `20 × 3 × 1.5 = 90 ≤ 120` holds.

- **Worst case > 3 minutes.** `--limit=20` now **breaches the §8 invariant**,
  and the lock can expire mid-run — which is how a recording gets scored twice.
  `--limit` and `withoutOverlapping` must move **together**. Recompute with
  your measured worst case `W` in minutes:

  ```
  limit × W × 1.5 ≤ lock
  ```

  Keep `limit ≥ 15` (a full roster must still clear in one run) and raise the
  lock to satisfy the inequality — e.g. a measured 5-minute worst case needs
  `20 × 5 × 1.5 = 150`, so `withoutOverlapping(180)`.

  Edit `routes/console.php`, then **run `php artisan test --filter=RescoreScheduleTest`**.
  It asserts the arithmetic and the `--limit=20` literal, so it will fail until
  both the schedule and the test's expectation match the new numbers. Update
  the deploy notes' table in the same change, and remember a longer lock means
  a longer stall after a killed run.

---

## Section E — a child reading on their own device

1. On the child's device, the child signs in with their **PIN** and opens their
   own dashboard.
   *Expect:* the reading you sent is waiting for them there, by passage name.

2. The child records and submits from that dashboard.
   *Expect:* the card on the teacher's board moves to `recorded`.

**There is no code-entry screen in this application** (ruling R36). The session
code on the teacher's board and panel is a **reference for the teacher's
records only** — no route and no controller looks it up. A child reaches their
reading by PIN login, nothing else.

*If anything on screen still invites a child to type a code* — a "join with
this code" instruction, a code field, a "give this code to the child" label —
**that is a bug to report**, not a feature to use. A teacher reading a code
aloud to fifteen children while no screen accepts it is a failure in front of a
whole class.

---

## What to write down when you are done

- median per recording, worst case per recording, T₁ − T₀ for 15
- whether the roster cleared in one run, and whether anything scored twice
- whether the unwatched batch reached `completed` on its own
- anything in Section C that behaved differently from "Expect"
- whether Section D forced a change to `--limit` / `withoutOverlapping`

## E. The setup form's per-child reading and Cancel (added 2026-10-22)

None of this has run in a browser — it is read and syntax-checked only. Shoot
at **500px wide or more**; narrower fakes an overflow bug in headless Edge.

1. **The child tick.** Open *Assess a group*. Each child's checkbox should be
   large (1.6rem), outlined, and hit-sized with the name beside it. Tick one:
   the row tints pale blue. *If the tick is small or the row does not tint, the
   CSS did not load — check `view:clear` and a hard refresh.*
2. **The stated reading.** Choose a language, type and material grade, then a
   batch material. Every ticked row should now read "📖 <that material's
   title>". Change the batch material: **every row's stated title changes with
   it.** *If a row still names the old passage, `syncBatchReading()` is not
   firing on the `default_material_id` change.*
3. **The override tick.** A ticked child shows "A different reading for
   <name>". Tick it: a dropdown appears and the stated line disappears. Choose a
   passage from a different grade — it is allowed on purpose.
4. **Turning the override off.** Untick it: the dropdown disappears and the
   stated line returns. **Submit now and that child must read the batch
   material, not the one you had briefly chosen.** *If the child reads the
   abandoned passage, the control is hidden but still submitting — the one bug
   this design exists to prevent.*
5. **Unticking the child.** With an override chosen, untick the **child**. The
   override tick and dropdown both disappear. Re-tick the child: the override
   starts off again, not remembered.
6. **The sixteenth child.** Tick fifteen; the sixteenth checkbox disables
   itself and the counter reads "15 of 15". Untick one and the others re-enable.
7. **Mixed grades.** Tick children from two grades. The grade select should
   preselect nothing and a note should name both grades. Pick a grade by hand,
   then tick another child: the select must **stop** following your ticks.
8. **Cancel on the summary.** Review a batch, then press *Cancel and discard*.
   It is an outlined red button, it asks before discarding, and Cancel-ing the
   confirmation leaves you on the page with your ticks. Confirming lands you on
   the batches list with **no batch created** — check the list is unchanged.

## F. Add child, and the loading indications (added 2026-10-23)

Neither screen below has been opened in a browser. Shoot at **500px or wider**.

1. **The loading indications.** Open a board with cards in several states. A
   spinner in the chip and a striped moving bar must appear on a `transcribing`
   card **and on no other** — not on recorded, queued, needs verdict, done or
   failed. Let a reading finish: both must stop. *They previously showed on
   every card for ever; if that returns, an element has two `class` attributes
   again.*
2. **Add child is offered only when it can work.** The button sits in the board
   header. Cancel a batch: the button disappears. Fill a batch to fifteen: it
   disappears. Visit `/assessments/batch/<id>/add` directly in either case —
   you should land back on the board with a warning, not an error.
3. **The picker.** It lists only children not already on this board. Tick one,
   optionally give them their own reading, and add. The new card appears as
   **waiting**, and the counter respects the batch as a whole — a board of
   fourteen offers one more place, not fifteen.
4. **A late child on a finished batch.** Complete every reading in a batch, then
   add a child. The batch must read **collecting**, not analysing — nothing is
   being analysed until that child reads and you send it. Send their reading and
   it should read analysing.
5. **Two tabs (known gap).** Two browser tabs each adding children can between
   them pass fifteen, because the cap is not row-locked. The result is a
   sixteenth card and a longer queue — no data is lost. Worth knowing; tell me
   if you want it closed.

## G. The buttons on a card, and the panel's layout (added 2026-10-23)

Not opened in a browser either. Shoot at **500px or wider**, and once wide.

1. **One row, not two.** On the board, a `waiting` card's **Conduct here** and
   **Remove** must sit beside each other on one line, and when the card is too
   narrow for both they wrap together rather than one sitting under the other.
   Check `done` too: **Check Result** and **Remove from batch** on one row.
2. **A transcribing card.** The sentence "Being analysed now — this one cannot
   be stopped." takes the whole width; it must not try to sit beside a button.
3. **The panel's card.** Open a child's panel. The card at the top fills the
   left column — not a third of it — and carries **no** Conduct here, no Send
   button of its own and no Remove. The chip, the words, Check Result and the
   player are all still there, and the player still plays.
4. **The panel's card keeps its shape while it changes.** Leave the panel open
   on a reading that is being analysed and let it finish. The card repaints to
   `needs verdict` (it is fetched from this page, not the board) and must
   **still** have no Conduct here and no Remove. If either appears, the poll is
   pulling the board's copy of the card.
5. **What the panel stopped showing.** No "Sit with … tap Start reading"
   sentence above the recorder, and no "On …'s own device" card. The session
   code is still on the card itself while the child is `waiting` — and nowhere
   once they have read, which is deliberate.
6. **Comprehension order.** On a comprehension reading, the passage card comes
   **first** and the questions below it. Read down the page as a teacher would:
   text, then questions, then Save answers.
