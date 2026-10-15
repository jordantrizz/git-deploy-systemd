# ==============================================================================
# -- git-deploy-systemd completion
# ==============================================================================
_debugload

# =====================================
# -- _git-deploy-systemd
# =====================================
function _git_deploy_systemd() {
    local -a commands
    commands=(
        'install:Install or reinstall the systemd service and directory structure'
        'uninstall:Fully remove the command, service, and all configuration'
        'add:Register a new site interactively (or via flags)'
        'remove:Unregister a site and regenerate the watcher'
        'update:Update the installed binary from the original source path'
        'update-all:Run update and plugin update in sequence'
        'plugin:Manage MU-Plugin installs on WordPress sites'
        'list:List all registered sites'
        'restart:Restart the watcher service'
        'help:Show help message'
    )

    local curcontext="$curcontext" state line
    typeset -A opt_args

    _arguments -C -s -S \
        '(-h --help)'{-h,--help}'[Show help message]' \
        '1: :->command' \
        '*:: :->args' \
        && return 0

    case "${state}" in
        command)
            _describe -t commands 'git-deploy-systemd command' commands
            ;;
        args)
            case "${line[1]}" in
                install)
                    _arguments -s -S \
                        '(--force)--force[Skip confirmation prompts]'
                    ;;
                uninstall)
                    _arguments -s -S \
                        '(--force)--force[Skip all confirmation prompts]' \
                        '(--keep-triggers)--keep-triggers[Leave .git-pull-trigger files in place]'
                    ;;
                add)
                    _arguments -s -S \
                        '(--path)--path[Absolute path to the git repository]:path:_files -/' \
                        '(--user)--user[System user to run git pull as]:user:_users' \
                        '(--name)--name[Optional label for the site]:name:' \
                        '--write-commit-info[Write commit info JSON after deploy]'
                    ;;
                remove)
                    _arguments -s -S \
                        '1:path:_files -/'
                    ;;
                update|update-all)
                    _arguments -s -S \
                        '(--from)--from[Specify a new source path for the binary]:path:_files' \
                        '(--force)--force[Force update even if md5sum matches]'
                    ;;
                plugin)
                    local -a plugin_cmds
                    plugin_cmds=(
                        'add:Register a WordPress install and copy mu-plugin'
                        'remove:Unregister a WordPress install'
                        'update:Update mu-plugin on all/specific installs'
                        'list:List all registered plugin installs'
                    )
                    _arguments -s -S \
                        '1: :->plugin_cmd' \
                        '*:: :->plugin_args'
                    case "${state}" in
                        plugin_cmd)
                            _describe -t plugin_cmds 'plugin subcommand' plugin_cmds
                            ;;
                        plugin_args)
                            case "${line[1]}" in
                                add|remove)
                                    _arguments -s -S \
                                        '(--path)--path[WordPress root path]:path:_files -/' \
                                        '(--label)--label[Site label]:label:'
                                    ;;
                                update)
                                    _arguments -s -S \
                                        '(--path)--path[Update only this WordPress root]:path:_files -/' \
                                        '(--force)--force[Force update even if md5sum matches]'
                                    ;;
                                list)
                                    # No additional arguments
                                    ;;
                                *)
                                    ;;
                            esac
                            ;;
                    esac
                    ;;
                list|restart|help)
                    # No additional arguments
                    ;;
                *)
                    ;;
            esac
            ;;
    esac
}

compdef _git_deploy_systemd git-deploy-systemd
