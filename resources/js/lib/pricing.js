// Live preview of the cost while the user fills in the form.
// It mirrors App\Services\Pixelforge\Pricing; the server prices the request
// again on submit and that result is the one that counts.

const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

/** "12.50" USD per 1M tokens → 12500 nanodollars per token, parsed as a string so it stays exact. */
function nanoPerToken(rate) {
    const [whole, fraction = ''] = String(rate).split('.');

    return Number(whole + fraction.padEnd(3, '0').slice(0, 3));
}

function chargeNano(usage, rates, markupBps) {
    const cost = TOKEN_TYPES.reduce((sum, type) => sum + usage[type] * nanoPerToken(rates[type]), 0);
    const scaled = cost * (10_000 + markupBps) + 9_999;

    return (scaled - (scaled % 10_000)) / 10_000; // integer division, rounding the markup up
}

export function estimate(pricing, markupBps, { prompt, size, quality, referenceImages }) {
    const { tokens, rates } = pricing;

    const text = Math.ceil([...prompt].length / tokens.characters_per_text_token);
    const textMin = Math.floor(text * (1 - tokens.text_token_variance) + 1e-9);
    const textMax = Math.ceil(text * (1 + tokens.text_token_variance) - 1e-9);
    const reasoning = tokens.reasoning[quality];

    const fixed = {
        input_image_tokens: referenceImages * tokens.per_reference_image,
        output_tokens: tokens.output[quality][size],
    };
    const charge = (textTokens, reasoningTokens) =>
        chargeNano({ ...fixed, input_text_tokens: textTokens, reasoning_tokens: reasoningTokens }, rates, markupBps);

    return {
        min: charge(textMin, reasoning.min),
        expected: charge(text, Math.floor((reasoning.min + reasoning.max) / 2)),
        max: charge(textMax, reasoning.max),
        tokens: { text, textMin, textMax, image: fixed.input_image_tokens, output: fixed.output_tokens, reasoning },
    };
}
