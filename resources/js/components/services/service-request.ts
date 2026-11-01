export type ServiceRequest = {
    service: string;
    area: string;
    language: string;
    insurance: string;
    notes: string;
};

export function serviceRequestPrompt(request: ServiceRequest): string {
    return [
        `Help me prepare what to ask when looking for a ${request.service}.`,
        request.area.trim() && `Area: ${request.area.trim()}.`,
        request.language.trim() && `Language: ${request.language.trim()}.`,
        request.insurance.trim() &&
            `Insurance preference: ${request.insurance.trim()}.`,
        request.notes.trim(),
    ]
        .filter(Boolean)
        .join(' ');
}

/** A message the person can review and share themselves; nothing is sent. */
export function serviceRequestMessage(request: ServiceRequest): string {
    return [
        `Hello, I’m looking for a ${request.service}${request.area.trim() ? ` in ${request.area.trim()}` : ''}.`,
        request.language.trim() &&
            `I’d prefer to communicate in ${request.language.trim()}.`,
        request.insurance.trim() &&
            `Insurance preference: ${request.insurance.trim()}.`,
        request.notes.trim(),
        'Could you let me know whether you can help and how to get started?',
    ]
        .filter(Boolean)
        .join(' ');
}
