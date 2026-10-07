/* Keep test handoffs visible in this isolated page instead of navigating away. */
ExpaduStore.update((state) => {
    state.prompt = 'Keep my existing request';
    state.brief.from = 'Neumarkt';
});
ExpaduStore.go = (page, params) => {
    document.querySelector('#sheet')?.close();
    document.querySelector('#journey-navigation')?.close();
    const proof = document.createElement('pre');
    proof.id = 'handoff-proof';
    proof.style.cssText =
        'white-space:pre-wrap;padding:20px;background:white;border-radius:16px;font-size:12px';
    proof.textContent = JSON.stringify(
        {
            page,
            params,
            prompt: ExpaduStore.read().prompt,
            journey: ExpaduStore.read().draft.at(-1),
        },
        null,
        2,
    );
    document.querySelector('#main').prepend(proof);
    proof.scrollIntoView();
};
