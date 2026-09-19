# Setting up EmailOctopus for the article newsletter

The signup form, `subscribe.php` and `bin/send-newsletter.php` (1.45.0) send new
articles to subscribers through EmailOctopus. EmailOctopus's API cannot create or send
a campaign, so each article goes out through an automation: for each subscriber the
site writes the article into their contact fields, then starts the automation for
them. That is why the setup below needs specific custom fields and a particular kind
of automation.

The field names and settings come from EmailOctopus's API documentation. Menu names in
their web interface may differ slightly.

## 1. Get your API key

In your EmailOctopus account settings, open **API keys** (under Integrations & API)
and create a key. You need it in step 6.

## 2. Set up the list

Create a list for the newsletter, or use an existing one.

- In the list's settings, **turn on double opt-in.** New subscribers are added as
  unconfirmed, and nobody gets an article until they click the confirmation link.
  It is under **Consent & customisation → Double opt-in email**. **If it is off,
  nothing fails and nothing is sent:** the site still adds each signup as pending,
  EmailOctopus accepts it without sending a confirmation email, and the contact
  stays pending for good, so it never receives an article either. Turning it on
  later does not send one to contacts already pending; delete them and have them
  sign up again.
- Note the **list ID**. It is in the list's settings, and usually in the page
  address when the list is open. It looks like `a1b2c3d4-...`.

## 3. Add four custom fields to the list

Create each as a **Text** field. The tag has to match exactly, including capitals:

| Tag | What it holds | Suggested fallback |
|---|---|---|
| `ArticleTitle` | The article's title | `A new article` |
| `ArticleUrl` | Full link to the article | your site's home page |
| `ArticleExcerpt` | The excerpt, or the opening text | *(leave empty)* |
| `ArticleImage` | Featured image link, blank if none | *(leave empty)* |

## 4. Create the automation

Create a new automation with these settings:

- **Trigger: Started via API.** It is the only trigger the site can start.
- **Turn on Allow contacts to repeat.** Without it, each subscriber gets the first
  article and then nothing ever again.
- Add **one email step, with no delay.** For example:
  - **Subject:** `{{ArticleTitle}}`
  - **Body:** `{{ArticleTitle}}` as a heading, then `{{ArticleExcerpt}}`, then a
    button or link to `{{ArticleUrl}}`, such as "Read the full article".
  - **Leave `{{ArticleImage}}` out at first.** Articles without a picture would show a
    broken image. Add it only if EmailOctopus can hide an image when the field is empty.
  - Check that the email has an unsubscribe link. EmailOctopus normally adds one to
    its footer.
- **Start (activate) the automation**, then note its **automation ID**. It appears
  only in the page address, `https://emailoctopus.com/automations/<automationId>`,
  while the automation is open. The API cannot list automations, so the address is
  the only place to find it.

## 5. Deploy 1.45.0

Deploy the code **before** entering the settings, so the database is migrated to
schema v32 and nginx knows about `subscribe.php`:

- Pull the code, run `composer install`, and reload PHP-FPM.
- Run the build as the web server's user, which migrates the database.
- Install the updated nginx config, test it and reload nginx. It adds the `subscribe`
  rate-limit zone and the `/subscribe.php` location.
- Add the cron job to the web server user's crontab. See
  [INSTALL.md → Newsletter (cron)](../INSTALL.md#newsletter-cron):

  ```
  0-59/5 * * * * /usr/bin/php /var/www/cms/bin/send-newsletter.php --quiet >> /var/www/cms/storage/newsletter.log 2>&1
  ```

## 6. Enter the settings

Go to **Admin → Settings → General → Newsletter** and fill in the API key, list ID
and automation ID, then save.

- If EmailOctopus rejects the key or list ID, the saved message includes a warning.
- The first save sets the start date (`newsletter_enabled_from`). Only articles
  published after this moment are ever emailed, so enabling the feature does not
  email the archive.
- The site rebuilds in the background, and the form appears under your articles.

## 7. Test it before anyone else subscribes

1. Subscribe with your own address using the form under any article.
2. **Check your inbox for EmailOctopus's confirmation email.** Their documentation
   does not say whether a contact added through the API as unconfirmed is sent one,
   so this has to be checked by hand. Then click the link.
3. For a standalone signup page, create a page with `[subscribe]` in its body.
4. When you next publish an article, wait about ten minutes, then run:

   ```bash
   sudo -u www-data php bin/send-newsletter.php --dry-run
   ```

   It should name the article, count one subscriber, and show exactly what goes
   into each field.
5. Within five minutes the cron job sends it for real. Check that the email arrives
   with the right title and link, and look at `storage/newsletter.log` if it does not.

## Day to day

- **To keep an article out of inboxes**, untick **Email to subscribers** in the editor.
  You have about ten minutes after publishing to do that.
- Notes, photo posts and replies are never emailed. Only titled articles are.
- An article is never sent more than three days late.
- A second article published within an hour of the first waits until the hour is up.
  The contact fields hold one article at a time, so sending the next one too soon
  could show a subscriber the newer article twice.
- Once an article has gone out, the editor shows how many subscribers it went to.
