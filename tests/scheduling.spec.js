const { test, expect } = require('./fixtures/fake-paycenter');

test.use({ extraHTTPHeaders: { 'X-Epay-Test': 'epay-qualification' } });

for (const scenario of ['normal', 'existing', 'race', 'duplicate']) {
  test(`@scheduling accepts a persisted reconciliation worker: ${scenario}`, async ({ request }) => {
    const response = await request.post('/wp-json/epay-test/v1/diagnostics/schedule', { data: { scenario } });
    expect(response.ok(), await response.text()).toBe(true);
    const result = await response.json();
    expect(result.scheduled).toBe(true);
    expect(result.errors).toEqual([]);
    expect(result.persisted).toBe(true);
    if (scenario === 'race') {
      expect(result.cached).toBe(false);
      expect(result.injected).toBe(true);
    }
  });
}

for (const scenario of ['missing', 'wrong_args', 'recurring', 'malformed', 'read_failure', 'write_failure', 'veto']) {
  test(`@scheduling preserves reconciliation scheduling failures: ${scenario}`, async ({ request }) => {
    const response = await request.post('/wp-json/epay-test/v1/diagnostics/schedule', { data: { scenario } });
    expect(response.ok(), await response.text()).toBe(true);
    const result = await response.json();
    expect(result.scheduled).toBe(false);
    expect(result.errors).toHaveLength(1);
    expect(result.errors[0]).toContain(scenario === 'veto' ? 'epay_test_schedule_denied' : 'could_not_set');
    if (['read_failure', 'write_failure', 'veto'].includes(scenario)) {
      expect(result.persisted).toBe(true);
    }
  });
}
