<p align="center"><img src="docs/banner.png" alt="GOBL(in) Workflow: visual flowchart workflows run by AI agents" width="100%"></p>

# Goblin Canvas

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white) ![database SQLite %7C MySQL](https://img.shields.io/badge/database-SQLite%20%7C%20MySQL-003B57?logo=sqlite&logoColor=white) ![agents Claude Code %7C Codex %7C Gemini CLI](https://img.shields.io/badge/agents-Claude%20Code%20%7C%20Codex%20%7C%20Gemini%20CLI-c4402f) [![license MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE) [![GitHub stars](https://img.shields.io/github/stars/goblin-red/Workflows-Canvas?style=social)](https://github.com/goblin-red/Workflows-Canvas/stargazers)

Goblin Canvas is a self-hosted editor for workflows made of blocks, arrows, files and instructions. Draw a scheme, give a terminal AI agent the leader link, and follow its work in the Run panel. You can also use the editor manually, with or without an AI provider.

[Open Goblin Canvas](https://canvas.goblin.red) · [Project website](https://goblin.red)

## Install locally

You need PHP 8.1 or newer, PDO SQLite or PDO MySQL, and the `curl`, `mbstring`, `zip` and `json` extensions. From this directory, run:

```sh
git clone https://github.com/goblin-red/Workflows-Canvas.git goblin
cd goblin
php bin/server.php install
php bin/server.php serve
```

Open <http://localhost:8080/>. Choose SQLite for a local database, or MySQL / MariaDB if you already have a database server. The installer stores settings and your selected credentials locally. It also publishes the English documentation and loads the built-in examples.

The default admin login and password are both `admin`. You can set a different password during setup or in the admin panel. Choose your own password before putting the app on a shared host. Keep AI provider fields blank to use the editor without provider keys.

## Host on a web server

Put the application in a private installation directory and have your web server serve `public/` only. The included root `.htaccess` can route an Apache installation to `public/` if `mod_rewrite` and overrides are available. Ensure PHP can write to `data/`, `storage/`, `workspace/` and the installation settings directory. Open `install.php` to configure the site, then open `admin.php` to manage it.

Keep `secrets.php`, databases, configuration overrides, backups and working files outside your public repository. Use your own provider keys if you enable the assistant, voice services or Jev.

## Draw and run a scheme

<p align="center"><img src="docs/wf-agent-roles-and-team.jpg" alt="A lead agent and worker agents running a flowchart" width="80%"></p>

- Choose from 48 starter schemes in **Catalog**, or create a scheme and add work blocks, arrows and files.
- In **Run**, copy the leader link for your terminal agent. Open the run in the editor to see its progress and results.
- If Jev leaves a decision open, the leader can choose a branch using the condition and previous results.
- Connect your own terminal agent from the account settings to answer in Canvas chat or edit schemes you authorize.
- Save the open scene as HTML or PDF, using either a flat diagram or the current color design.

Open `first-steps.php` for the illustrated guide, or `documentation.php` for the developer reference. The agent instructions also live in [`instructions/en`](instructions/en/).

## Maintain an installation

```sh
php bin/server.php migrate
php bin/server.php migrate --apply
php md_backend/docs/publish.php
```

The first command lists pending schema updates. See `php bin/graph-migrate.php --help` for the separate conversion of data from the earlier editor. Existing installations keep their own databases, project files, keys and settings.

The PHP server code is in `lib/`, the web client is in `public/src/`, and SQL schema and migration files are in `sql/`. No JavaScript build step is required.

## More GOBL(in) apps

Free and open source, from the makers of [GOBL(in)](https://goblin.red):

| App | What it does |
| --- | --- |
| [GOBL(in) Remote](https://github.com/goblin-red/Goblin-Remote) | self-hosted remote desktop for macOS in any browser, over cheap PHP hosting |
| [GOBL(in) Voice](https://github.com/goblin-red/Orca-Voice) | voice control and dictation for Claude Code, Codex and Orca on macOS |
| [GOBL(in) Session Viewer](https://github.com/goblin-red/Session-Viewer) | every Claude Code, Codex, Grok and OpenCode session in one window |
| [GOBL(in) Drag & Taskbar](https://github.com/goblin-red/Drag-and-Taskbar) | move windows with trackpad gestures, a real taskbar and Alt-Tab for macOS |
| [GOBL(in) Convert](https://github.com/goblin-red/Photo-Convert) | fast batch JPEG converter and photo resizer for macOS |

## License

[MIT](LICENSE).
