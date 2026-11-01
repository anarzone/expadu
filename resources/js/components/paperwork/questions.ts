// The plan's question goes through a question session: the server offers it, then
// accepts one answer or deferral for that offer. Loading the page never starts one.

import { CommandError, commands } from './api';
import type { Plan, Question } from './types';

export interface Offer {
    session: number;
    id: number;
    token: string;
}

async function freshOffer(session: number, question: Question): Promise<Offer> {
    let response = await commands.nextQuestion(session);

    // A run of questions pauses on purpose; asking for this one carries on.
    if (response.status === 'paused') {
        await commands.resumeQuestions(session, false);
        response = await commands.nextQuestion(session);
    }

    if (
        response.status !== 'offered' ||
        response.question?.fact_key !== question.fact_key
    ) {
        throw new CommandError(
            'This question changed. The page has the latest one now.',
            409,
        );
    }

    return {
        session,
        id: response.question.id,
        token: response.question.token,
    };
}

/** The server's offer for the plan's current question, starting a session when needed. */
export async function offerFor(
    plan: Plan,
    jurisdiction: string,
    question: Question,
): Promise<Offer> {
    const session = plan.questions.session_id;

    if (session && question.id && question.token) {
        return { session, id: question.id, token: question.token };
    }

    if (session) {
        try {
            return await freshOffer(session, question);
        } catch (error) {
            // An expired session keeps its answers; carry on in a new one.
            if (!(error instanceof CommandError) || error.status !== 410) {
                throw error;
            }
        }
    }

    const started = await commands.startQuestions(plan.person_id, jurisdiction);

    return freshOffer(started.session_id, question);
}
