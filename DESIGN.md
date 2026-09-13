---
name: ePay native administration
description: Native WordPress and WooCommerce payment review and order evidence surfaces.
colors:
  focus-blue: "#2271b1"
  error-red: "#b32d2e"
  divider-gray: "#dcdcde"
typography:
  emphasis:
    fontWeight: 600
spacing:
  compact-gap: "6px"
  paragraph-gap: "8px"
  section-inset: "12px"
  section-gap: "16px"
components:
  disclosure-summary:
    typography: "{typography.emphasis}"
  review-error:
    textColor: "{colors.error-red}"
---

# Design System: ePay native administration

## Overview

**Creative North Star: "Native WordPress/WooCommerce"**

The review queue and ePay order panel belong to the surrounding administration interface. Their visual language is compact, familiar and factual: native tables, ordinary controls, local feedback and expandable payment evidence.

This record covers those two surfaces only. WordPress and WooCommerce own the surrounding typography, control styling, navigation and metabox chrome. The frontmatter records the small recurring additions in [the review stylesheet](assets/css/epay-paycenter-review.css); it is not a replacement theme or a specification for unrelated gateway settings.

**Key Characteristics:**

- Native controls and document hierarchy.
- Readable evidence with explicit unknown and error states.
- Responsive rows and stable keyboard context.

## Colors

The host supplies the admin palette; plugin additions identify focus, errors and attempt boundaries.

### Primary

- **Focus blue:** outlines disclosure summaries and the feedback target reached after acknowledgement.

### Secondary

- **Error red:** failed review messages beside the affected action; the text explains the failure.

### Neutral

- **Divider gray:** thin boundaries between recorded attempts. Table striping, backgrounds and ordinary text remain host styles.

**The Explicit State Rule.** State is written in words; color alone never communicates a payment outcome or review failure.

## Typography

Use the host's system font, page heading, section heading, body, description and control styles. References use native inline code styling and remain selectable. There is no plugin display face or independent type ramp.

Disclosure summaries and definition-list labels use the emphasis weight. Order links and case categories use semantic strong text. Keep sentence case and allow English and Greek labels to wrap.

**The Host Hierarchy Rule.** Express hierarchy with native headings, descriptions, strong text and code before adding a new type treatment.

## Layout

The queue uses the native admin wrapper, category views, search, bulk controls and striped table. Reason paragraphs are bounded at (70ch); the desktop action column is (150px). Recovery details wrap in a flex row with a column gap of (24px). Shared spacing tokens cover paragraph separation, attempt padding and section separation.

At the native breakpoint (782px), category links give way to a labeled full-width select. Search stays in normal flow, bulk controls wrap, and each case becomes a two-column grid: a selection rail (36px) and a flexible content column. Keep the visible select-all label. Long references wrap without truncation.

The order panel uses a definition list with a label column (120–180px) and flexible values. It becomes one column at the same breakpoint. Preserve WordPress's larger mobile controls; both ordinary and small buttons inherit its minimum height (40px).

## Elevation & Depth

Plugin additions are flat. Native table striping, metabox boundaries and thin attempt dividers provide separation. Preserve the host's control focus treatments; custom disclosure and feedback outlines use a stroke (2px) and offset (2px). There are no custom decorative shadows or animated transitions on these surfaces.

## Shapes

Retain native rectangular tables, metaboxes and gently rounded controls. The plugin introduces no radius scale, decorative containers or custom icon system. Use real disclosure summaries and their native markers.

## Components

- **Controls and navigation:** use native buttons, small buttons, search fields, selects, checkboxes, category links and pagination. The current category has `aria-current="page"`. Inputs and selection controls have associated or accessible labels. The sidecar's button examples snapshot the qualification site's WordPress styles; production continues to inherit them.
- **Recovery disclosure:** opens when recovery needs attention. Earlier attempts use a separate disclosure beneath the latest attempt. Both summaries have a visible keyboard focus outline.
- **References and evidence:** show the complete selectable reference with a small copy button and adjacent polite feedback. Clipboard failure explains manual copying. Show missing values explicitly as “Not recorded” or “Reference unavailable.” Transaction links depend on recorded evidence; AdminTool links retain their sign-in and manual-search guidance.
- **Acknowledgement:** only unconfirmed and historical cases expose bulk selection. Saving disables relevant controls, changes the action label and marks the scope busy. Remove only confirmed saved rows; keep failed rows and their inline alerts. Refresh counts or explicitly report that counts are unavailable.
- **Keyboard and feedback:** polite status feedback has a programmatic focus target. After saving, preserve scroll and restore the original action when it remains; otherwise focus the next remaining action, then a preceding action, then the status message. Errors preserve the evidence and the action. Empty feedback and hidden content occupy no space.
- **Progressive enhancement:** the queue retains ordinary form submission without JavaScript. Copy and select-all controls appear only when enhanced. Order-panel actions use non-submit buttons; without JavaScript, the panel links to the review queue.

## Do's and Don'ts

### Do:

- Do inherit WordPress and WooCommerce controls, typography and metabox chrome.
- Do preserve complete references, readable wrapping and visible keyboard focus.
- Do keep unknown results, failures and saved acknowledgements explicit in text.
- Do check both English and Greek at desktop and mobile widths.

### Don't:

- Don't turn these admin surfaces into a separate dashboard or brand system.
- Don't use reviewed styling or copy to imply that payment, order or stock state changed.
- Don't remove failed cases or replace unavailable counts with zero.
- Don't ship qualification screenshots as product assets; they contain synthetic test evidence and are excluded from releases.
