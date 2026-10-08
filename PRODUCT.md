# Product

<!-- impeccable:product-schema 1 -->

Source: `MASTER-PROMPT.md` (Fassung 2026-10-07_08-57) and the stage 0 answers in `docs/stufe-0.md`. Markus approved this brief; it was not re-interviewed.

## Platform

web

## Stack

React, Vite, TypeScript, Tailwind CSS v4 for the UI. The API runs as PHP 8 with SQLite on all-inkl shared hosting (variant 2, chosen by Markus in stage 0).

## Users

Markus Wanning, host of „Der Monteur-Podcast – Vermietung an Fachkräfte und Monteure“. He opens the cockpit from the existing podcast-admin (menu item „Analytics“), on the desktop and on the phone, between production sessions. He reads, answers and approves. He never types numbers, uploads CSVs or refreshes data by hand.

## Product Purpose

One place that answers three questions:
1. How many people does the podcast reach, in total and per platform?
2. How does each episode perform, on which platform?
3. Which comments and questions are open, and how do I answer them quickly?

## Operating Context

- Two episodes per week, self-hosted RSS feed, platforms: YouTube, Spotify, Apple Podcasts, Amazon Music, Deezer, Podcast Index, website player.
- The server collects everything with an interface (feed, YouTube, Apple reviews, access logs). A weekly Claude routine (Monday 08:20, on Markus' Mac in Chrome) collects Spotify, later Apple and Amazon, and imports them.
- Nothing goes out without Markus' explicit click: every reply, moderation and publication.

## Capabilities and Constraints

- Reach = sum of the lead values of YouTube, Spotify, feed downloads (IAB 2.2) and website player. Not a head count.
- Missing values show „–“ with a reason, never 0. Every number shows its source and date.
- Interface German, informal „du“, short sentences, buttons say what happens.
- Internal tool: noindex. Mobile from 360 px, no horizontal page scroll, WCAG 2.2 AA.
- Never use the word „Kurzzeitvermietung“; never name KWATERA24, MMW Holding, unit counts or employer.

## Brand Commitments

Design system „Plakat“ of the Monteur-Podcast, toned down for a work tool (master prompt section 11): ink #0A0F16, podcast blue #2E75B5, pink #E48B9B, white; grey #8B98A8 only for disabled. Geist 400–900 and Geist Mono 600, self-hosted. 3–4 px ink borders, hard offset shadows without blur, no rounded corners. No gradients, glass, glow, further accents or platform brand colors; platforms are recognized by an ink logo and their name.

## Evidence on Hand

No real numbers exist in the repo. Test data is only used in tests and is marked as such.

## Product Principles

- Reading, answering, approving. Nothing else is Markus' job.
- Every number is traceable to source and time.
- Honest gaps over invented values.
- The tool disappears into the task; the poster style lives in details.

## Accessibility & Inclusion

WCAG 2.2 AA, full keyboard use, visible 4 px blue focus, tables as alternative to every chart, touch targets at least 44 × 44 px.
