<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Kodithuwakku Hardware POS: project rules

Laravel Boost is already installed (`boost.json`, `AGENTS.md`). This project is a copy of the CITIZENS Agro POS (`C:\projects\Citizens`), changed for a hardware shop.

- **No git.** Never run `git init`, commit, push or any other git command here (owner's instruction). Review changes by reading files.
- **Task log.** At the end of every request, update `docs/TASK_LOG.md` (current status table, phase progress, decisions, a new dated log entry at the top). The plan is `docs/HARDWARE_PLAN.md`; the Citizens design docs are in `docs/citizens-reference/`.
- **Line endings are LF.** Python edits must open files with `newline=''`; check edited files with `file <path>` (must not say CRLF).
- **Keep apart from Citizens on this machine:** DB `kodithuwakkuDB` (tests: `kodithuwakkuDB_testing`), Redis/cache/Scout prefixes `kodithuwakku…`, Reverb port 8081. Browser checks run against a throwaway DB via `php -S` from `public/` (`php artisan serve` ignores DB env vars).
