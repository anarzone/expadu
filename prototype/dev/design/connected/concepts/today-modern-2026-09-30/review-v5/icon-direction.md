# Icon refresh

The owner asked to replace the outdated calendar and arrow styling during the
Today review. Use **Phosphor Regular** consistently for this proposed revision.
The exact source SVGs are saved in `icons/`, with their MIT licence and a source
manifest. Do not implement guessed paths traced from the generated boards.

[Exact icon specimen](icon-refresh.png) · [Editable SVG specimen](icon-refresh.svg)

| Meaning | Icon | Treatment |
| --- | --- | --- |
| Events, date, Your day | `calendar-dots` | Simple dated calendar; no face-like pair of dashes. |
| Add to plan | `calendar-plus` | One plus inside the calendar. Keep separate from Save. |
| Open appointment or paperwork | `caret-right` | One small bare chevron, with a full row hit area. Remove the large circular arrow button. |
| Open choice | `caret-down` | Same regular geometry, aligned to the label. |
| Travel direction | `arrow-right` | Short arrow between endpoints, not a general-purpose action decoration. |
| Alternative routes | `arrows-split` | On the compact primary recovery action, with a short label. |
| Save | `bookmark-simple` | Existing translucent/frosted photo control. Selected state remains distinct. |

All supporting icons come from the same regular family. The current Composer
sparkle, tray and its internal controls stay as the established component; the
logo remains a separate task. Do not substitute an arbitrary star for Composer.

Target glyph sizes are 20–24px, in touch areas at least 44px. Use regular weight
throughout. Avoid a mix of thin, bold, filled and duotone symbols. Filled status
and selection states are separate intentional component states.

Generated raster boards illustrate composition and may approximate individual
glyphs. This exact vector specimen and the original SVG files govern the icon
geometry when the page is implemented.

## Sources

- [Phosphor Icons](https://phosphoricons.com/) — official icon catalogue.
- [Official SVG source repository](https://github.com/phosphor-icons/core) —
  regular assets and catalogue; documentation checked through Context7.
- [MIT licence](https://github.com/phosphor-icons/core/blob/main/LICENSE) —
  retained locally in `icons/LICENSE`.

Assets retrieved 30 September 2026. `icons/sources.json` contains their original
source URLs and SHA-256 hashes. These design assets do not install a dependency
or change any application components.
