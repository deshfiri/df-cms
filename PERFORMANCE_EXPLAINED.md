# How Performance Is Calculated

A plain-English guide to the score on your DFCP COMS scorecard: where every
number comes from, what lifts it, and what never counts.

**In one sentence:** each month, every person gets a score out of 100, worked out
from up to six areas of work, and only the areas that actually apply to them.

- [The score and what it means](#the-score-and-what-it-means)
- [The six areas](#the-six-areas)
- [Putting the final score together](#putting-the-final-score-together)
- [How tasks are credited when people share work](#how-tasks-are-credited-when-people-share-work)
- [Area 1: Task Completion](#area-1-task-completion)
- [Area 2: On-Time Delivery](#area-2-on-time-delivery)
- [Area 3: Quality](#area-3-quality)
- [Area 4: Sales Achievement](#area-4-sales-achievement)
- [Area 5: Client Satisfaction](#area-5-client-satisfaction)
- [Area 6: Client Care](#area-6-client-care)
- [What never counts](#what-never-counts)
- [When the numbers update](#when-the-numbers-update)
- [Who can see and change what](#who-can-see-and-change-what)
- [A full worked example](#a-full-worked-example)
- [Common questions](#common-questions)

---

## The score and what it means

Your score is a number from 0 to 100 for one calendar month. It carries a label:

| Score | Level |
|---|---|
| 90 and above | Excellent |
| 80 – 89 | Very Good |
| 70 – 79 | Good |
| 60 – 69 | Needs Improvement |
| Below 60 | Poor |

You'll find it under **Performance**: the **Scoreboard** ranks everyone for a
month, and clicking a name opens that person's **Scorecard**, which shows every
number behind the score, including the task-by-task credit.

---

## The six areas

| Area | What it measures | Default weight |
|---|---|---|
| Task Completion | How much of the work due this month you finished | 20 |
| On-Time Delivery | How much of what you finished met its deadline | 20 |
| Quality | How often work came back because of a mistake | 15 |
| Sales Achievement | Money collected against your sales target | 15 |
| Client Satisfaction | Star ratings clients gave you — *not being collected yet* | 15 |
| Client Care | Bringing clients in and looking after them | 15 |

Weights are set by management and can differ per department or per person, so
check **Performance → Configuration** for the ones in force. The table above is
what applies when nothing has been customised.

Nobody is scored on all six every month. An area with nothing to measure is
skipped — see [Putting the final score together](#putting-the-final-score-together).

---

## Putting the final score together

Two rules decide how the six areas combine.

**1. An area only counts if it applies to you.** If there is no data for it — no
sales target set, no client ratings received, no client work at all — it is
skipped rather than scored as zero. An area whose weight is set to 0 is shown on
the scorecard but cannot raise or lower your score.

**2. The remaining weights are rebalanced to 100.** Skipping an area never
shrinks your score; the areas that do apply simply carry more of it.

### Example

Anika has tasks and client work this month, but no sales target and no client
ratings. Four areas apply:

| Area | Her score | Weight | Rebalanced weight | Contribution |
|---|---|---|---|---|
| Task Completion | 80 | 20 | 20 ÷ 70 = 28.57% | 22.86 |
| On-Time Delivery | 75 | 20 | 28.57% | 21.43 |
| Quality | 90 | 15 | 15 ÷ 70 = 21.43% | 19.29 |
| Client Care | 60 | 15 | 21.43% | 12.86 |
| **Final** | | | **100%** | **76.43 — Good** |

The weights of the four areas add up to 70, so each is divided by 70 to spread
it across the full 100.

> The total is worked out before anything is rounded, so adding up the rounded
> contributions above can land a hundredth away from the final score.

---

## How tasks are credited when people share work

A task rarely belongs to one person from start to finish. Credit follows **what
each person actually did**, not who happened to hold the task at the end.

Everything is taken from the task's own history, so any number can be traced
back to a logged event. Each action earns work points:

| Action | Points | Most one person can earn on one task |
|---|---|---|
| Started work (first time) | 2 | 2 |
| Resumed work later | 0.5 | 1 |
| Added a file | 2 | 6 |
| Commented | 0.5 | 1.5 |
| Submitted the work | 4 | 8 |
| Holding the task now | 1 | 1 |

Comments and files only earn points for people **doing** the work — whoever has
held the task, or a colleague helping who neither requested nor reviewed it. A
manager commenting on work they handed out is directing it, not doing it.

Your **share** of a task is your points divided by the points of everyone doing
the work.

### Example

Rafi starts a task (2 points) and uploads two files (4 points), then hands it to
Mim, who submits it (4 points) and holds it at the end (1 point).

- Rafi: 6 points → 6 ÷ 11 = **0.55 of the task**
- Mim: 5 points → 5 ÷ 11 = **0.45 of the task**

That task then counts as 0.55 of a task for Rafi and 0.45 for Mim, in both the
completion and on-time figures.

**Who gets nothing:** whoever only asked for the task, whoever only reviewed it,
and anyone it passed through without them doing anything. Getting no share means
the task neither helps nor hurts them.

**Working alone:** your share is 1, so every figure is exactly what it would be
without any of this.

---

## Area 1: Task Completion

> How much of the work due this month you finished.

**Which tasks count:** every task whose **deadline falls in the month** that you
hold now or earned work points on. A task due in March counts in March even if
it was created in January or finished in April.

```
Task Completion % = your share of completed tasks ÷ your share of all counted tasks × 100
```

**Cancelled tasks** are left out entirely, unless management has switched on
"count cancelled against KPI" in the performance settings.

The scorecard also shows plain counts — total, completed, in progress, pending,
overdue, cancelled — so you can see the shape of the month. The **Overdue**
count ignores work you have already submitted and that is waiting on a reviewer:
someone else's delay is not your overdue.

**What lifts it:** finishing work in the month it was due, and getting deadlines
moved *before* they pass if the date is no longer realistic.

---

## Area 2: On-Time Delivery

> Of the work you finished, how much met its deadline.

```
On-Time % = your share of tasks finished on time ÷ your share of all tasks you finished × 100
```

Only tasks due this month that you actually completed count here, so this cannot
be dragged down by work still in progress.

**How a deadline is judged:**

- A deadline with a **time** ("14 March, 4:00 pm") is met up to that moment.
- A deadline with **only a date** ("14 March") is met any time that day.

The scorecard splits your completions into **early**, **on deadline** and
**late**, and shows the average delay of the late ones in days.

**What lifts it:** finishing before the deadline, and asking for an extension
early — a deadline that was formally moved is judged on its new date.

---

## Area 3: Quality

> How often your work had to be done again because of a mistake.

When a reviewer sends work back, they pick a reason. Only one reason affects
this score:

| Reason the work came back | Affects Quality? |
|---|---|
| Employee Mistake | **Yes** |
| Client Requested | No |
| Scope Change | No |
| Management Requested | No |

```
Mistake rate = your share of tasks sent back for a mistake ÷ your share of work you handed in × 100
Quality score = 100 − mistake rate
```

The base is the work due this month that you handed in: tasks you completed,
plus any that were sent back at least once.

The scorecard still lists **every** revision, including client requests and scope
changes, so the history is complete — they just don't count against you.

**What lifts it:** getting work approved first time. Checking the brief and the
required files before submitting is the whole game here.

---

## Area 4: Sales Achievement

> Money collected against the target set for you.

This area only applies if a manager has set you a **sales target for that
month**. With no target, it is skipped.

```
Achieved   = all payments marked Paid, dated in the month, from clients assigned to you
Sales %    = achieved ÷ target × 100   (capped at 100)
```

Payments count on their **payment date**, and only when they are marked Paid, so
a promise or a pending proof does not count until it is recorded as received.

The scorecard also shows what's remaining and the daily run-rate needed to reach
the target before the month ends.

**What lifts it:** getting payments recorded promptly and keeping client
assignments accurate — a payment from a client assigned to someone else counts
for them, not you.

---

## Area 5: Client Satisfaction

> What clients said about your work.

> **Not in use yet.** The system can store client ratings and score them, but
> there is currently no screen where a client leaves one. Until ratings start
> being collected, this area is skipped for everyone and its weight is shared
> among the other areas. The rest of this section describes how it will score
> once ratings exist.

Ratings are 1 to 5 stars, left by a client against the member of staff who
handled a support ticket or a workflow stage.

```
Satisfaction score = average rating × 20
```

So 5 stars = 100, 4 stars = 80, 3 stars = 60. Only ratings received **in that
month** count. A rating can be marked as excluded — an unfair one, say — and an
excluded rating is left out of the average completely.

The scorecard shows how many ratings you received, how many were positive
(4 or 5) and how many were complaints (1 or 2).

**What lifts it:** responsive, complete work on tickets and stages that reach
the client.

---

## Area 6: Client Care

> Bringing clients in, and looking after the ones you are responsible for.

**Your clients** are the ones assigned to you, plus any you added that are not
assigned to anyone. **Active** clients are those marked Running or Warning.

### Points

| What you did | Points |
|---|---|
| Added a client by hand | 3 each |
| A day on which you did real work on one of your clients | 1 per day, per client |

Days are capped at **4 per client per month** — ten edits to one client in one
day count once, and one client cannot carry your whole month. Clients that
arrived through an import don't earn the 3 points: an import is data entry, not
bringing a client in.

**What counts as looking after a client:**

- editing the client, or changing its status
- adding a note, a product update, a project update or a document
- scheduling or completing a meeting
- adding or editing a brand
- replying to its support ticket
- opening its client portal account

### The score

```
Activity % = points ÷ monthly target × 100   (capped at 100, target is 20 points by default)
Coverage % = your active clients you worked on ÷ all your active clients × 100
Score      = average of activity and coverage
             (activity alone if you have no active clients)
```

### Example

Shila added 2 clients by hand (6 points) and worked on 5 of her clients: one on
six separate days (capped to 4) and four others on one day each (4) — 8 upkeep
days in total.

- Points: 6 + 8 = 14 → activity = 14 ÷ 20 = **70%**
- She has 10 active clients and worked on 5 of them → coverage = **50%**
- Client Care score = (70 + 50) ÷ 2 = **60**

**If you have no clients, added none and did no client work**, this area is
skipped, so it never drags down someone whose job isn't client-facing.

**What lifts it:** spreading attention across your whole active list rather than
a favourite few — coverage is half the score.

---

## What never counts

- **Opening or viewing anything.** Reading a client page or a task is not
  recorded and earns nothing. Clicking around all day changes no number.
- **Being copied in.** Watching a task you never worked on gives you no share of
  it — and no penalty either.
- **Volume for its own sake.** Every action type is capped: ten comments earn no
  more than three comments' worth, and files stop counting after three files.
- **Reviewing.** Approving or returning someone's work is recorded separately and
  never takes credit away from the people who did the work.

---

## When the numbers update

- **Live.** Scores recalculate from current data every time the Scoreboard or a
  Scorecard is opened, so today's work shows today.
- **Frozen monthly.** On the 1st of each month at 2:00 am, the month just ended
  is written into history with each person's **company rank** and **department
  rank**. That snapshot is what **Performance → History** shows later.
- People with nothing scorable in a month — no tasks due, no target, no ratings,
  no client work — are not ranked for that month.

---

## Who can see and change what

| You need | To |
|---|---|
| **View performance** | Open the Scoreboard, anyone's Scorecard, History and Workload |
| **Manage performance** | Change weights, set sales targets, edit performance settings and capacity |

Both are granted per role in **Settings → Roles**.

What a manager can change in **Performance → Configuration**:

- **Weights**, set for the whole company, for a department, or for one person.
  The most specific one wins: a person's own profile beats their department's,
  which beats the company default.
- **Sales targets** per person per month.
- **Settings**, including whether cancelled tasks count against task completion,
  and the monthly points target for Client Care.
- **Capacity** per person, used by workload and auto-assignment.

---

## A full worked example

Anika, Design department, for one month.

**Her raw numbers**

| Area | What happened | Score |
|---|---|---|
| Task Completion | 10 tasks due; she completed 8 of them | 80 |
| On-Time Delivery | Of 9 completions, 7 met the deadline | 77.78 |
| Quality | 12 handed in, 2 returned for her mistake (16.67%) | 83.33 |
| Sales | No target set for her | *skipped* |
| Satisfaction | No client ratings this month | *skipped* |
| Client Care | Activity 70%, coverage 50% | 60 |

**Her final score**

Four areas apply, with weights 20, 20, 15 and 15 — 70 in total.

```
Task Completion  80    × (20 ÷ 70) = 22.86
On-Time          77.78 × (20 ÷ 70) = 22.22
Quality          83.33 × (15 ÷ 70) = 17.86
Client Care      60    × (15 ÷ 70) = 12.86
                                     ─────
Final score                          75.79  →  Good
```

Her strongest area is Quality, her weakest is Client Care — the scorecard names
both, so the next month has an obvious target.

---

## Common questions

**Why is an area showing a dash?**
There was no data for it that month, so it was skipped and the other areas
carried the score. A dash is never a zero.

**I finished everything, so why isn't my Task Completion 100%?**
Tasks count in the month their **deadline** falls. Work you finished this month
that was due last month counts towards last month. Look at the task list on your
scorecard — it names every task counted and the share credited to you.

**Someone else finished my task. Do I get nothing?**
You get the share your work earned — starting it, files, comments, submissions.
Only people who did nothing at all get no share.

**A client asked for changes and my Quality dropped. Why?**
It shouldn't have. Only returns marked **Employee Mistake** affect Quality. If a
client request was logged as a mistake, ask the reviewer to correct the reason.

**My task was late because I was waiting on someone else.**
Work you have submitted and that is waiting for review is not counted overdue.
If the delay was elsewhere, ask for the deadline to be moved before it passes —
a deadline that was formally changed is judged on its new date.

**Why does my colleague's score use different weights?**
Weights can be set per department or per person. Performance → Configuration
shows which profile applies to whom.

**The score looks wrong.**
Every figure is traceable. The scorecard lists each task, your role on it, the
points you earned, the events that earned them, and the share credited — so any
total can be checked by hand. If something still looks off, raise it with a
manager with the month and the task in question.
