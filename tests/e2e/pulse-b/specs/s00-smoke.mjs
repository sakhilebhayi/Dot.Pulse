import { launch, person, Spec, axe, shot, settle } from '../lib.mjs';

export default async function (ctx) {
    const s = new Spec('s00', ctx);
    const browser = await launch();
    const a = await person(browser, ctx.base, { userId: ctx.seed.users.anele.id, spec: s });
    await a.page.goto('/floor');
    await settle(a.page);
    s.check('floor loads', a.page.url().includes('/floor'), a.page.url());
    await shot(a.page, s, 'floor');
    await axe(a.page, s, 'floor');
    s.check('no console errors', a.errors.length === 0, a.errors.join(' | '));
    await browser.close();
    return s.result();
}
