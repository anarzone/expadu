# Expadu for iPhone

Native SwiftUI app. The plan and its reasons are in the research report (`reports/`, not in git); this folder holds the code.

- `Expadu.xcodeproj`: Xcode 27's `.xcproj` format. Sources are a synchronized folder, so new files need no project edit.
- `Expadu/`: the app. `Packages/`: `Core` (no UI, `swift test`), `DesignSystem` (colours, type, motion).
- iOS 26.0 is the floor; build with Xcode 27.
- Design: the mock (`prototype/dev/design`) for the look, the live app wherever the two differ.
- Motion: views name a `MotionToken`, never a number. Debug builds open the motion lab on a shake or `expadu://lab`.

```bash
cd ios/Packages && swift test
```
