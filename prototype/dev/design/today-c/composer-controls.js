/* The approved Composer controls, available directly from Today. */
(function () {
    const S = ExpaduStore,
        tray = document.querySelector('#composer-tray');
    const field = document.querySelector('#request');
    const launcher = tray.querySelector('.composer-launcher');
    const send = tray.querySelector('[type="submit"]');
    const actions = tray.querySelector('.today-composer-actions');
    const approval = tray.querySelector('.today-approval');
    const tools = tray.querySelector('.today-composer-tools');
    const context = tray.querySelector('#included-ideas');
    let expandedState = null,
        motion = null;
    function setExpanded(expanded, restoreFocus = false) {
        if (expandedState === expanded) return;
        const previous = expandedState;
        expandedState = expanded;
        motion?.cancel();
        motion = null;
        launcher.setAttribute('aria-expanded', String(expanded));
        tools.inert = !expanded;
        field.inert = !expanded;
        if (context) context.inert = !expanded;
        if (!expanded) {
            actions.open = false;
            approval.open = false;
        }
        const finish = () => {
            tray.classList.toggle('is-active', expanded);
            if (!expanded && restoreFocus)
                launcher.focus({ preventScroll: true });
        };
        if (expanded) tray.classList.toggle('is-active', true);
        if (
            previous === null ||
            matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            finish();
            return;
        }
        const resting = { opacity: 1, transform: 'translateY(0) scale(1)' };
        const tucked = {
            opacity: 0,
            transform: 'translateY(10px) scale(0.96)',
        };
        const animation = tray.animate(
            expanded ? [tucked, resting] : [resting, tucked],
            {
                duration: expanded ? 260 : 180,
                easing: expanded
                    ? 'cubic-bezier(.16,1,.3,1)'
                    : 'cubic-bezier(.4,0,1,1)',
                fill: 'both',
            },
        );
        motion = animation;
        animation.finished.then(
            () => {
                if (motion !== animation) return;
                motion = null;
                finish();
                animation.cancel();
            },
            () => {},
        );
    }
    tray.addEventListener('focusin', (event) => {
        if (event.target !== launcher) setExpanded(true);
    });
    // The button always opens the box; a conversation to go back to is a
    // line inside it (composer-entry), not a jump to Composer's page (owner,
    // 16 Oct 2026: "it opens as a whole page").
    launcher.addEventListener('click', () => {
        setExpanded(true);
        field.focus({ preventScroll: true });
    });
    tray.querySelector('.composer-dismiss').addEventListener('click', () => {
        setExpanded(false, true);
    });
    // Touch/WebKit controls may blur the field without taking focus. Dismiss
    // through an outside pointer, the close control, or Escape, never blur.
    document.addEventListener('pointerdown', (event) => {
        if (!tray.contains(event.target)) {
            if (tray.contains(document.activeElement))
                document.activeElement.blur();
            setExpanded(false);
        }
    });
    setExpanded(false);

    tray.querySelectorAll('[data-icon]').forEach((el) => {
        el.innerHTML = ExpaduIcon(el.dataset.icon);
    });
    tray.querySelectorAll('.today-control-menu button').forEach((el) => {
        el.setAttribute(
            'aria-label',
            el.querySelector('strong')?.textContent || el.textContent.trim(),
        );
    });
    const mode = () =>
        S.studio()?.conversation?.approval || S.read().approvalMode || 'ask';
    function sync() {
        send.disabled = !field.value.trim();
        const current = mode();
        approval.querySelector('[data-approval-label]').textContent =
            current === 'routine' ? 'Edit for me' : 'Ask first';
        approval
            .querySelectorAll('[data-today-approval]')
            .forEach((button) =>
                button.setAttribute(
                    'aria-checked',
                    String(button.dataset.todayApproval === current),
                ),
            );
    }
    field.addEventListener('input', sync);
    for (const menu of [actions, approval])
        menu.addEventListener('toggle', () => {
            if (menu.open)
                for (const other of [actions, approval])
                    if (other !== menu) other.open = false;
        });
    tray.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.composerPanel || button.dataset.composerAction) {
            S.update((s) => {
                s.prompt = field.value;
            });
            S.go('composer', {
                panel: button.dataset.composerPanel,
                action: button.dataset.composerAction,
            });
        }
        if (button.dataset.todayApproval) {
            const value = button.dataset.todayApproval;
            const workspace = S.studio();
            if (workspace?.conversation) {
                workspace.conversation.approval = value;
                try {
                    localStorage.setItem(
                        'expadu-composer-studio-v1',
                        JSON.stringify(workspace),
                    );
                } catch {
                    return;
                }
            }
            S.update((s) => {
                s.approvalMode = value;
            });
            approval.open = false;
            approval.querySelector('summary').focus({ preventScroll: true });
            sync();
        }
    });
    document.addEventListener('click', (event) => {
        if (!actions.contains(event.target)) actions.open = false;
        if (!approval.contains(event.target)) approval.open = false;
    });
    tray.addEventListener('keydown', (event) => {
        const activeMenu = event.target.closest('.today-approval');
        if (
            activeMenu?.open &&
            ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)
        ) {
            event.preventDefault();
            const buttons = [
                ...activeMenu.querySelectorAll('[data-today-approval]'),
            ];
            const index = buttons.indexOf(document.activeElement);
            const next =
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? buttons.length - 1
                      : (index +
                            (event.key === 'ArrowDown' ? 1 : -1) +
                            buttons.length) %
                        buttons.length;
            buttons[next].focus();
            return;
        }
        if (event.key !== 'Escape') return;
        const menu = [actions, approval].find((x) => x.open);
        if (menu) {
            event.preventDefault();
            menu.open = false;
            menu.querySelector('summary').focus();
        } else {
            event.preventDefault();
            document.activeElement?.blur();
            setExpanded(false, true);
        }
    });
    window.addEventListener('pageshow', sync);
    window.addEventListener('pageshow', () => {
        if (S.isComposerMinimized?.()) setExpanded(false);
    });
    window.addEventListener('storage', sync);
    sync();
})();
