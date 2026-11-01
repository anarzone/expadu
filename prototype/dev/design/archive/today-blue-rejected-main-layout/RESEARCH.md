# Today: main-page design pass

7 September 2026. Research applied to the accepted Today prototype.

## What the research changes

| Guidance | Application to Today |
| --- | --- |
| [NN/g: Visual hierarchy](https://www.nngroup.com/articles/visual-hierarchy-ux-definition/) — use scale, contrast and grouping to communicate relative importance. | Shorter greeting/header, fewer competing labels, aligned discovery headings. The clarity panel starts sooner. Cyan carries progress and selected choices; dark text carries information. |
| [NN/g: Cards](https://www.nngroup.com/articles/cards-component/) — a card groups a concise summary and provides entry to its details. | Places use a larger rounded image with the title, context and availability caveat directly below. Keep is a separate, consistently positioned control. Events form a compact shared list. Extra frames, repeated “Discover” labels and decorative image category badges are removed. |
| [W3C: Focus not obscured](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html) — fixed interface elements must not completely cover the focused control. | Composer keeps its fixed position but loses the broad fading backdrop. Actual dock height determines bottom clearance, including selected ideas. Keyboard focus scrolls above the dock. |

These sources support the principles; the specific arrangement, sizes and Expadu palette are design judgments for this product. They do not prove that users prefer this version.

## Connection to the report

The [6 September report](../composer-first/report.md) identified competing attention across planning, attention tiles, discovery and widgets. This pass addresses that finding on the opening page: less header height, one grouped clarity panel, coherent discovery and a smaller Composer footprint.

The report's original proposal to hide discovery until a request, and its earlier palette, were superseded by the user's later decisions. Places, events, all service navigation, the clarity title and Expadu cyan/orange therefore remain.

The report also identified missing intent constraints, weak evidence behind matches and fragile handoffs. Styling cannot resolve those service/data issues. The prototype retains request text, editable choices and explicit saving; unverified content and generated imagery remain labelled. No legal figures or venue claims were added.

## Visible differences

- At 390 pixels wide, the clarity panel begins at **258px**, previously **334px**: 76px earlier.
- Mobile shows paperwork, departures and weather together. Its panel grows to accommodate all three; discovery begins 16px later than before. This is a deliberate tradeoff for a genuinely useful overview rather than hiding two services in a swipe row.
- Places have more image area and less framing. Events share a list with clearer icon, title and metadata alignment. Both sections keep their own navigation.
- The unselected Composer footprint shrinks from **88px to 72px**, and the page-wide fade is removed. Selected ideas can still increase its height.
- Brand cyan stays **#05BADD**. Dark text on cyan measures **5.71:1**; white on the darker orange action **#D9340A** measures **4.72:1**. These are sampled token checks, not a complete accessibility audit.

## Review and verification

Thirteen focused tests pass. Browser checks covered 320, 390 and 1200px; no horizontal page overflow in the inspected states. Reviewed screenshots on mobile and desktop, limited-content layout, 44px keep controls, focus above the expanded dock, two-item Composer handoff, save/return and preserved choices after a priority change. No browser console errors were observed.

**Verdict:** the opening page has substantive visual and layout improvements. It is ready for visual review. The remaining design question is whether the fuller mobile clarity panel earns its space; real-data integration remains a separate production requirement. No new homepage sections are recommended at this stage.
