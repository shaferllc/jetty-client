<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Generate shell completion scripts for Bash, Zsh, and Fish.
 */
final class ShellCompletions
{
    public static function bash(): string
    {
        return <<<'BASH'
# Bash completion for jetty CLI
# Install: eval "$(jetty completions bash)"
# Or:      jetty completions bash >> ~/.bashrc

_jetty_completions() {
    local cur prev
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"

    local commands="share list delete login logout setup onboard config doctor domains replay update self-update version help completions"
    local global_flags="--api-url= --token= --config= --region="

    if [ "$COMP_CWORD" -eq 1 ]; then
        COMPREPLY=( $(compgen -W "$commands $global_flags" -- "$cur") )
        return 0
    fi

    local cmd="${COMP_WORDS[1]}"

    case "$cmd" in
        share|http)
            local share_flags="--site= --subdomain= --server= --host= --bind= --local= --local-host= --serve= --route= --routes-file= --expires= --health-path= --print-url-only --skip-edge --edge --no-body-rewrite --no-js-rewrite --no-css-rewrite --no-resume --force --no-health-check --delete-on-exit --no-detect -v -f"
            COMPREPLY=( $(compgen -W "$share_flags" -- "$cur") )
            ;;
        config)
            if [ "$COMP_CWORD" -eq 2 ]; then
                COMPREPLY=( $(compgen -W "get set list" -- "$cur") )
            elif [ "$COMP_CWORD" -eq 3 ]; then
                COMPREPLY=( $(compgen -W "server api-url token subdomain domain tunnel-server" -- "$cur") )
            fi
            ;;
        completions)
            COMPREPLY=( $(compgen -W "bash zsh fish" -- "$cur") )
            ;;
    esac
    return 0
}

complete -F _jetty_completions jetty
BASH;
    }

    public static function zsh(): string
    {
        return <<<'ZSH'
# Zsh completion for jetty CLI
# Install: eval "$(jetty completions zsh)"
# Or:      jetty completions zsh > "${fpath[1]}/_jetty"

_jetty() {
    local -a commands
    commands=(
        'share:Share a local port via tunnel'
        'list:List active tunnels'
        'delete:Delete a tunnel'
        'login:Authenticate with Bridge'
        'logout:Clear authentication'
        'setup:Configure API URL and token'
        'onboard:First-time setup wizard'
        'config:View or set configuration'
        'doctor:Diagnose installation issues'
        'domains:Manage subdomains and custom domains'
        'replay:Replay a captured request'
        'update:Update to latest version'
        'version:Show version'
        'help:Show help'
        'completions:Generate shell completions'
    )

    _arguments -C \
        '1:command:->cmds' \
        '*::arg:->args'

    case "$state" in
        cmds)
            _describe 'command' commands
            ;;
        args)
            case "${words[1]}" in
                share|http)
                    _arguments \
                        '--site=[Local hostname]:hostname:' \
                        '--subdomain=[Requested subdomain]:label:' \
                        '--server=[Tunnel server]:server:' \
                        '--route=[Routing rule /path=port]:rule:' \
                        '--routes-file=[Routes JSON file]:file:_files' \
                        '--expires=[Auto-expire duration]:duration:' \
                        '--print-url-only[Print URL and exit]' \
                        '--no-resume[Force new tunnel]' \
                        '--force[Override duplicate check]' \
                        '--delete-on-exit[Delete tunnel on exit]' \
                        '--skip-edge[Skip edge connection]' \
                        '--no-detect[Skip port auto-detection]' \
                        '-v[Verbose output]' \
                        '-f[Force]' \
                        '*:port:'
                    ;;
                config)
                    if (( CURRENT == 2 )); then
                        _values 'subcommand' get set list
                    elif (( CURRENT == 3 )); then
                        _values 'key' server api-url token subdomain domain tunnel-server
                    fi
                    ;;
                completions)
                    _values 'shell' bash zsh fish
                    ;;
            esac
            ;;
    esac
}

compdef _jetty jetty
ZSH;
    }

    public static function fish(): string
    {
        return <<<'FISH'
# Fish completion for jetty CLI
# Install: jetty completions fish > ~/.config/fish/completions/jetty.fish

complete -c jetty -f

# Commands
complete -c jetty -n '__fish_use_subcommand' -a 'share' -d 'Share a local port via tunnel'
complete -c jetty -n '__fish_use_subcommand' -a 'list' -d 'List active tunnels'
complete -c jetty -n '__fish_use_subcommand' -a 'delete' -d 'Delete a tunnel'
complete -c jetty -n '__fish_use_subcommand' -a 'login' -d 'Authenticate with Bridge'
complete -c jetty -n '__fish_use_subcommand' -a 'logout' -d 'Clear authentication'
complete -c jetty -n '__fish_use_subcommand' -a 'setup' -d 'Configure API URL and token'
complete -c jetty -n '__fish_use_subcommand' -a 'onboard' -d 'First-time setup wizard'
complete -c jetty -n '__fish_use_subcommand' -a 'config' -d 'View or set configuration'
complete -c jetty -n '__fish_use_subcommand' -a 'doctor' -d 'Diagnose installation issues'
complete -c jetty -n '__fish_use_subcommand' -a 'domains' -d 'Manage subdomains and custom domains'
complete -c jetty -n '__fish_use_subcommand' -a 'replay' -d 'Replay a captured request'
complete -c jetty -n '__fish_use_subcommand' -a 'update' -d 'Update to latest version'
complete -c jetty -n '__fish_use_subcommand' -a 'version' -d 'Show version'
complete -c jetty -n '__fish_use_subcommand' -a 'help' -d 'Show help'
complete -c jetty -n '__fish_use_subcommand' -a 'completions' -d 'Generate shell completions'

# share flags
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'site' -d 'Local hostname (e.g. mysite.test)' -r
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'subdomain' -d 'Requested subdomain label' -r
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'server' -d 'Tunnel server name' -r
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'route' -d 'Routing rule (/path=port)' -r
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'routes-file' -d 'Load routes from JSON file' -r -F
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'expires' -d 'Auto-expire (e.g. 30m, 1h)' -r
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'print-url-only' -d 'Print URL and exit'
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'no-resume' -d 'Force new tunnel'
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'force' -d 'Override duplicate check'
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'delete-on-exit' -d 'Delete tunnel on exit'
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'skip-edge' -d 'Skip edge connection'
complete -c jetty -n '__fish_seen_subcommand_from share http' -l 'no-detect' -d 'Skip port auto-detection'
complete -c jetty -n '__fish_seen_subcommand_from share http' -s 'v' -d 'Verbose output'
complete -c jetty -n '__fish_seen_subcommand_from share http' -s 'f' -d 'Force'

# config subcommands
complete -c jetty -n '__fish_seen_subcommand_from config' -a 'get' -d 'Get a config value'
complete -c jetty -n '__fish_seen_subcommand_from config' -a 'set' -d 'Set a config value'
complete -c jetty -n '__fish_seen_subcommand_from config' -a 'list' -d 'List all config'

# completions shells
complete -c jetty -n '__fish_seen_subcommand_from completions' -a 'bash zsh fish'
FISH;
    }

    public static function generate(string $shell): string
    {
        return match (strtolower(trim($shell))) {
            'bash' => self::bash(),
            'zsh' => self::zsh(),
            'fish' => self::fish(),
            default => throw new \InvalidArgumentException(
                "Unknown shell: {$shell}. Supported: bash, zsh, fish"
            ),
        };
    }
}
