# Review-request email — Reader Team automation (send 7 days after signup)

To be pasted into Brevo, inside the Automation workflow described below. Two subject line options are given — pick one, or A/B test both.

## Setup in Brevo (once)

1. Go to **Automations** → create a new workflow (or edit the one you may already have started).
2. Trigger: **Contact added to a list** → select the **Reader Team** list (the one `BREVO_READER_LIST_ID` points to).
3. Add a **Wait** step: **7 days**.
4. Add a **Send email** step, and paste in the subject + content below.
5. Turn the workflow **on**.

Because the site already sends `FIRSTNAME`, `BOOK_TITLE` and `BOOK_LINK` as attributes on every contact it adds to this list, you can use Brevo's personalization tags below and they'll fill in automatically — no manual work per reader.

**Important before this goes live:** right now the free book ("How to Create Passive Income Using AI") has no Amazon link saved in its catalog entry, so `BOOK_LINK` would arrive empty and the review button below would have nowhere to send people. Open the admin panel and add that book's Amazon product page URL in the **Link** field, the same way you did for the paid books — the review email needs it even though the book itself is given away free from the site.

`FIRSTNAME` will often be blank too, since the instant-download popup only asks for an email address (only the manual Reader Team form asks for a name). The copy below is written to work fine either way — it never assumes a name is present.

---

## Subject line (pick one)

- **A:** Got a minute for an honest review?
- **B:** How's "{{contact.BOOK_TITLE}}" treating you so far?

## Preview text (optional, shows next to the subject in the inbox)

You don't even need to have bought the book to leave one.

## Email body

Hi,

A little while ago you grabbed a free copy of **{{contact.BOOK_TITLE}}** — I hope you've had a chance to dig into it.

If you have, I'd love to ask you for something small: an honest review on Amazon. It makes a real difference for an independent author — reviews are how new readers decide whether to trust a book they've never heard of.

One thing a lot of people don't realize: **you don't need to have bought the book on Amazon to leave a review there.** Amazon lets anyone with an account in good standing post what's called an "unverified" review — it just won't carry the little "Verified Purchase" badge, but it counts exactly the same and is completely within Amazon's rules.

It doesn't need to be long. Two or three honest sentences about what you liked (or didn't) are more than enough — and it doesn't have to be five stars. I'd rather have a real opinion than a polite one.

**[Leave your review here]({{contact.BOOK_LINK}})**

One more thing: once you've left it, just reply to this email and let me know — I'll personally unlock a second book from my library for you, completely free, as a thank-you.

Thanks for reading,
Michael

---

P.S. — If you'd like to mention in your review that you got the book for free, that's completely fine and honestly appreciated: readers tend to trust reviews more when that's out in the open, and it keeps everything transparent. Totally your call, not a requirement.

---

## Notes on why it's written this way

- It never asks for or implies a positive review — Amazon explicitly bans that, and doing it risks the review (or the whole account) getting pulled.
- The "unverified reviews are allowed" line is accurate per Amazon's current Community Guidelines: reviews from people who didn't buy the item through Amazon are permitted, they simply don't get the Verified Purchase badge.
- The P.S. about disclosing the free copy is optional, not mandatory under Amazon's own rules, but it's the safer, more transparent practice (it's roughly what the FTC recommends for any endorsement made after receiving something for free) and it costs nothing to include.
- The "reply and I'll unlock a second book" line ties directly into the Reader Team loop already live on the site.
