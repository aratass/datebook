# Release Notes for Datebook

## 1.0.0 - 2026-10-07

### Added
- A Datebook section in the control panel with month, week and list views of your content.
- Entries appear on the day they go live and, optionally, on the day they expire.
- Scheduled drafts appear on the day they will be published, and failed ones show the reason.
- Filters by site, section, author and status.
- Drag an item to another day to reschedule it. The time of day stays the same.
- Set an exact date and time from the details popup of any item.
- Scheduled drafts: publish a draft of a live entry at a set time, from the draft editor sidebar.
- Drafts are checked before they go live, in every site, and only by people who may publish them.
- `php craft datebook/drafts/publish` and `php craft datebook/drafts/list` console commands.
- A queue job that publishes due drafts when nobody runs the console command.
- An "Upcoming content" dashboard widget for the next 14 days.
- A private calendar feed (ICS) for each user, for Google Calendar, Outlook and Apple Calendar. The link can be reset at any time.
- Optional emails when a scheduled draft is published or cannot be published. The texts can be edited in Utilities, System Messages.
- Permissions to reschedule items, schedule drafts and use calendar feeds.
