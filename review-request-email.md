# Review-request email — sent automatically 7 days after signup

**Superseded note:** this is no longer sent through Brevo's automation editor — it's now sent directly by the site's own code (`reviewRequestEmailHtml()` in `api.php`), on a daily schedule handled by the `review-email-cron` Railway service. Kept here as a readable reference for the exact copy that goes out. Any wording change should be made in `api.php`, not here.

**Update — click-to-confirm instead of "reply to this email":** Brevo cannot reliably detect replies without a separate Inbound Parse setup (its own subdomain + MX records, which would conflict with the mailbox already running on `michaelcollins.pro`). So the email now uses a one-click confirmation button instead of asking the reader to reply.

That button (`action=confirm_review&token=...` in `api.php`) takes the reader to a simple page on the site listing every book they haven't received yet **that has a PDF uploaded** — this deliberately includes books marked "Paid" on the storefront, not just the one marked "Free". So a book can stay for sale on Amazon and still be handed out for free through this reward loop, as long as its PDF is uploaded in the admin panel; without a PDF a book simply never appears in this catalog, paid or free. Each book is a clickable "Send me this one" card. Picking one (`action=claim_next_book`) emails that book immediately, records it, and queues a *new* review-request email for it after the same delay — so the loop repeats automatically for book 2, book 3, and so on, for as long as there are unclaimed books with a PDF. Once a reader has claimed everything available, the confirmation page just thanks them instead of showing an empty catalog.

**Important:** right now the free book ("How to Create Passive Income Using AI") has no Amazon link saved in its catalog entry, so nothing gets queued for it — without a link the review email would have nowhere to send people. Open the admin panel and add that book's Amazon product page URL in the **Link** field, the same way you did for the paid books — the review email needs it even though the book itself is given away free from the site.

The greeting is deliberately name-free ("Hi,") since the instant-download popup only asks for an email address — only the manual Reader Team form collects a name.

---

## Subject line

Got a minute for an honest review?

## Email body

Hi,

A little while ago you grabbed a free copy of **{book title}** — I hope you've had a chance to dig into it.

If you have, I'd love to ask you for something small: an honest review on Amazon. It makes a real difference for an independent author — reviews are how new readers decide whether to trust a book they've never heard of.

One thing a lot of people don't realize: **you don't need to have bought the book on Amazon to leave a review there.** Amazon lets anyone with an account in good standing post what's called an "unverified" review — it just won't carry the little "Verified Purchase" badge, but it counts exactly the same and is completely within Amazon's rules.

It doesn't need to be long. Two or three honest sentences about what you liked (or didn't) are more than enough — and it doesn't have to be five stars. I'd rather have a real opinion than a polite one.

**[Leave your review here]({book link})**

Once you've left it, tap the button below and I'll personally unlock the next book from my library for you, completely free, as a thank-you:

**[I left my review — send my next book]({confirm link})**

Thanks for reading,
Michael

---

## Notes on why it's written this way

- It never asks for or implies a positive review — Amazon explicitly bans that, and doing it risks the review (or the whole account) getting pulled.
- The "unverified reviews are allowed" line is accurate per Amazon's current Community Guidelines: reviews from people who didn't buy the item through Amazon are permitted, they simply don't get the Verified Purchase badge.
- No mention of the reader disclosing they got the book for free — removed per feedback, it wasn't adding anything useful.
- The confirmation button ties directly into the Reader Team loop already live on the site, and repeats book after book instead of stopping after just one extra unlock.
