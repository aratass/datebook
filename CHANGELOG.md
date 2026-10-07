# Release Notes for Datebook

## 1.0.0 - 2026-10-07

### Added
- A Datebook section in the control panel with month, week and list views of your content.
- Entries appear on the day they go live and, optionally, on the day they expire.
- Scheduled drafts appear on the day they will be published, and failed ones show the reason.
- Filters by site, section, author and status.
- Drag an item to another day to reschedule it. The time of day stays the same.
- Set an exact date and time from the details popup of any item.
- Datebook asks before a move hides a live entry or makes an entry live right away. It compares the date and the time.
- Scheduled drafts: publish a draft of a live entry at a set time, from the draft editor sidebar. Drafts can be scheduled in the sections that are on the calendar.
- Drafts are checked before they go live, in every site, and only by people who may publish them. Changes made to the live entry in the meantime are kept.
- A draft that was moved or unscheduled while drafts were being published stays a draft.
- `php craft datebook/drafts/publish` and `php craft datebook/drafts/list` console commands.
- Queue jobs that publish due drafts when nobody runs the console command. While drafts are scheduled, a job runs at least every 15 minutes and at the minute a draft is due. No job waits longer than 15 minutes, the longest delay Craft Cloud's queue accepts.
- Publish jobs go ahead of other queue jobs. Datebook looks in the queue before it adds one, so they do not pile up, even without a working cache.
- A problem with the queue never stops a schedule from being saved.
- Uninstalling takes Datebook's waiting publish jobs out of the queue.
- An "Upcoming content" dashboard widget for the next 14 days.
- A private calendar feed (ICS) for each user, for Google Calendar, Outlook and Apple Calendar. The link can be reset at any time.
- A "Feed link address" setting. In headless mode, feed links use the control panel's address.
- Optional emails when a scheduled draft is published or cannot be published. The texts can be edited in Utilities, System Messages.
- Permissions to reschedule items, schedule drafts and use calendar feeds.
