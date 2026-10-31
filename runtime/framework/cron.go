package loom

import (
	"fmt"
	"strconv"
	"strings"
	"time"
	_ "time/tzdata"
)

type cronField struct {
	values   map[int]bool
	wildcard bool
}
type Cron struct {
	fields   [5]cronField
	location *time.Location
}

func ParseCron(expression, zone string) (*Cron, error) {
	parts := strings.Fields(expression)
	if len(parts) != 5 {
		return nil, fmt.Errorf("Cron 需要五个字段：分 时 日 月 周")
	}
	loc, err := time.LoadLocation(zone)
	if err != nil || zone == "" || zone == "Local" {
		return nil, fmt.Errorf("请使用明确的 IANA 时区，例如 Asia/Shanghai")
	}
	c := &Cron{location: loc}
	mins := []int{0, 0, 1, 1, 0}
	maxs := []int{59, 23, 31, 12, 7}
	for i, p := range parts {
		f := cronField{values: map[int]bool{}, wildcard: strings.HasPrefix(p, "*")}
		for _, item := range strings.Split(p, ",") {
			step := 1
			bits := strings.Split(item, "/")
			if len(bits) > 2 {
				return nil, fmt.Errorf("无效 Cron 步长")
			}
			if len(bits) == 2 {
				step, err = strconv.Atoi(bits[1])
				if err != nil || step < 1 || step > maxs[i]+1 {
					return nil, fmt.Errorf("无效 Cron 步长")
				}
			}
			lo, hi := mins[i], maxs[i]
			if bits[0] != "*" {
				bounds := strings.Split(bits[0], "-")
				if len(bounds) > 2 {
					return nil, fmt.Errorf("无效 Cron 范围")
				}
				lo, err = strconv.Atoi(bounds[0])
				if err != nil {
					return nil, fmt.Errorf("Cron 字段仅支持数字、*、范围、列表和步长")
				}
				hi = lo
				if len(bounds) == 2 {
					hi, err = strconv.Atoi(bounds[1])
					if err != nil {
						return nil, fmt.Errorf("无效 Cron 范围")
					}
				} else if len(bits) == 2 {
					return nil, fmt.Errorf("步长须用于 * 或范围")
				}
			}
			if lo < mins[i] || hi > maxs[i] || hi < lo {
				return nil, fmt.Errorf("Cron 字段超出范围")
			}
			for n := lo; n <= hi; n += step {
				v := n
				if i == 4 && n == 7 {
					v = 0
				}
				f.values[v] = true
			}
		}
		c.fields[i] = f
	}
	return c, nil
}

// Next is strictly after the supplied instant. Iterate absolute time so DST gaps
// are skipped and repeated local minutes each represent a distinct occurrence.
func (c *Cron) Next(after time.Time) (time.Time, error) {
	t := after.Truncate(time.Minute).Add(time.Minute)
	end := after.AddDate(8, 0, 0)
	for t.Before(end) {
		local := t.In(c.location)
		dom, dow := c.fields[2].values[local.Day()], c.fields[4].values[int(local.Weekday())]
		day := dom && dow
		if !c.fields[2].wildcard && !c.fields[4].wildcard {
			day = dom || dow
		}
		if !c.fields[3].values[int(local.Month())] || !day || !c.fields[1].values[local.Hour()] {
			t = t.Add(time.Duration(60-local.Minute()) * time.Minute)
			continue
		}
		if c.fields[0].values[local.Minute()] {
			return t, nil
		}
		t = t.Add(time.Minute)
	}
	return time.Time{}, fmt.Errorf("未来八年内没有匹配的执行时间")
}
