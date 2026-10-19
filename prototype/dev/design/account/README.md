# Sign in and create an account

Built 19 Oct 2026 from the owner's choice, **A · On the street**, on the
"Sign in and create an account" canvas
(https://claude.ai/artifact/4tBjL2FBfNnUjB94JGG9dm).

Cologne's street from Events fills the page (`street.webp`, cut from the
Events wall: skyline, poster column, wall). The form is a cream poster taped
to the wall, with a marker label and a purple heading in the posters' type
(the Events fonts, `../events-next/fonts/`). Laptops show the whole street
with the poster on the wall's open stretch; phones and narrow windows show a
strip of skyline above it.

Each state has its own address (`app.js`):

- `#sign-in` (the default): Google, Apple, email, password with Show and
  Forgot password?, Keep me signed in.
- `#mismatch`: "That email and password don't match", above the fields; the
  email stays and the password takes the focus.
- `#create`: name, email, one password with Show (at least 8 characters; the
  app sends it as its own confirmation), and the privacy line linking the
  app's `/datenschutz`.
- `#check-email`: the address the link went to, Send the link again, Wrong
  email? Start again.
- `#forgot`, then `#sent`: the reset link is on its way; it works for 60
  minutes.
- `#new-password`: one field; saving returns to sign in with "Your password
  is changed."

The mock signs nobody in: Sign in, Google and Apple open Today, and the
emailed links are samples that say so. Sign out in the account menu (Today's
sheet, the other pages' header popover) opens this page.
