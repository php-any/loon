package loom

import (
	"testing"
	"time"
)

func TestCronCalendar(t *testing.T) {
	cases := []struct{ expr, zone, after, want string }{
		{"*/15 9-17 * * 1-5", "Asia/Shanghai", "2026-10-30T01:07:00Z", "2026-10-30T01:15:00Z"},
		{"0 9 * * 1", "Asia/Shanghai", "2026-10-30T01:00:00Z", "2026-11-02T01:00:00Z"},
		{"0 0 29 2 *", "UTC", "2026-10-30T00:00:00Z", "2028-02-29T00:00:00Z"},
		{"0 0 1 * 1", "UTC", "2026-10-30T00:00:00Z", "2026-11-01T00:00:00Z"},
		{"0 0 * * 7", "UTC", "2026-10-30T00:00:00Z", "2026-11-01T00:00:00Z"},
		{"0,30 * * * *", "UTC", "2026-10-30T00:00:00Z", "2026-10-30T00:30:00Z"},
		{"30 2 * * *", "America/New_York", "2026-03-08T06:59:00Z", "2026-03-09T06:30:00Z"},
		{"30 1 * * *", "America/New_York", "2026-11-01T05:30:00Z", "2026-11-01T06:30:00Z"},
	}
	for _, tc := range cases {
		t.Run(tc.expr+tc.after, func(t *testing.T) {
			c, err := ParseCron(tc.expr, tc.zone)
			if err != nil {
				t.Fatal(err)
			}
			after, _ := time.Parse(time.RFC3339, tc.after)
			next, err := c.Next(after)
			if err != nil || next.UTC().Format(time.RFC3339) != tc.want {
				t.Fatalf("got %s %v want %s", next, err, tc.want)
			}
		})
	}
	for _, expr := range []string{"* * * *", "60 * * * *", "*/0 * * * *", "1-0 * * * *", "1,,2 * * * *", "0 0 * 13 *", "0 0 * * 8", "0 0 ? * *"} {
		if _, err := ParseCron(expr, "UTC"); err == nil {
			t.Errorf("accepted %q", expr)
		}
	}
	if _, err := ParseCron("* * * * *", "Unknown/Timezone"); err == nil {
		t.Fatal("unknown zone")
	}
	c, _ := ParseCron("0 0 31 2 *", "UTC")
	if _, err := c.Next(time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)); err == nil {
		t.Fatal("impossible date")
	}
}
