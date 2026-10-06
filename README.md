# Roster

Every team, roster and player, for [Flarum](https://flarum.org) 2.

Browse by conference or division, open a club, and see who is on it.

![The Roster index: clubs grouped by conference, with crests](screenshots/index.png)

A club's roster, grouped the way the sport groups it:

![An Ohio State roster: offence by position, with class, height, weight and hometown](screenshots/roster.png)

## Which leagues

| League | Source | What you get |
|---|---|---|
| College football | ESPN | Rosters with class years |
| NFL, NBA, MLB, NHL, MLS, Premier League, WNBA | ESPN | The current roster, with position, number, size and hometown |

Every competition is a tick box in **Admin → Roster**,
and they cost nothing: ESPN needs no API key.

```sh
composer require ernestdefoe/roster
php flarum migrate
php flarum cache:clear
```

`roster:sync` runs hourly on Flarum's scheduler and needs nothing else.

## Player pages

Every name on a roster is a link to that player's own page:

- **His bio:** photo, number, position, height, weight, class, hometown, and his next game
- **About:** a summary from Wikipedia, but only when the article is certainly about him. The title must be his name, and the summary must mention his school and football, so a player who shares a name with someone famous never gets the wrong man's story.
- **Highlights:** ESPN's clips about him, played on the page in ESPN's own embeddable player
- **Career stats** by season and his **recent games**
- **News:** ESPN's stories that actually name him

Every section only appears when there's something in it. ESPN pads a quiet player's news with whatever college football is talking about that day, so stories that never name him are left out; a walk-on gets his bio and nothing invented.

What ESPN and Wikipedia return is cached for six hours per player (a week for Wikipedia), so a busy roster doesn't turn into a stream of outside requests. With [Waymark](https://github.com/ernestdefoe/waymark) installed, the roster pages carry its breadcrumb trail: Home › Roster › Texas › Arch Manning.

## Things worth knowing

🚨 **The position groups are the provider's own.** Offence, defence and
specialists is a fact about gridiron; ESPN answers Pitchers and Catchers,
Centers and Wingers. Mapping a shortstop through a list of football positions
returns "other" for every player on the team, so the group is stored as it was
given and only gridiron's four are translated.

🚨 **A professional club has no class column.** There is no such thing as a
sophomore in the NBA, and an empty column under a heading that means nothing in
the sport reads as missing data rather than as a concept the sport lacks.

🚨 **A box score is one call per club on ESPN**, so the sync is capped at a
dozen rosters a run and works oldest-first. A club whose roster is a day old is
invisible; a queue worker killed by its own traffic is not.

🚨 **A club with an empty roster is still stamped as fetched.** ESPN assembles
that endpoint from its own upstream calls, and one athlete with a missing record
takes a whole club's roster down with a 404 — seen live on one NFL club while
the other thirty-one answered. Without the stamp that club is retried first on
every run for ever, and no other club is ever reached.

## Requires

- Flarum 2

## Support

- **Support forum:** [Roster on ernestdefoe.online](https://ernestdefoe.online/d/112)
- **Bug reports:** [GitHub issues](https://github.com/ernestdefoe/roster/issues)

## Licence

MIT.
