/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
** documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
** rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to
** permit persons to whom the Software is furnished to do so, subject to the following conditions:
**
** The above copyright notice and this permission notice shall be included in all copies or substantial portions
** of the Software.
**
** THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
** WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
** COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
** TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
** SOFTWARE.
**/

package profiler

import (
	"context"
	"maps"
	"slices"
	"strconv"
	"strings"
	"time"

	"golang.zabbix.com/sdk/errs"
)

const (
	commandEnable      = "periodic_prof_enable"
	commandDisable     = "periodic_prof_disable"
	commandExecute     = "periodic_prof_execute"
	commandSetInterval = "periodic_prof_set_interval"
)

var (
	errEmptyCommand           = errs.New("empty command")
	errFailedToParseInterval  = errs.New("failed to parse interval")
	errInvalidParameterNumber = errs.New("invalid number of parameters")
	errIntervalOutOfRange     = errs.New("interval out of range")
	errProfilerBusy           = errs.New("profiler is busy")
	errProfilerStopped        = errs.New("profiler is stopped")
	errTooManyParameters      = errs.New("too many parameters")
	errUnknownCommand         = errs.New("unknown command")
)

type commandHandler func(context.Context, []string) (string, error)

// Commands returns profiler runtime command names.
func (c *Controller) Commands() []string {
	return slices.Sorted(maps.Keys(c.handlers))
}

func (c *Controller) executeCommand(ctx context.Context, params []string) (string, error) {
	if len(params) == 0 {
		return "", errEmptyCommand
	}

	handler, ok := c.handlers[params[0]]
	if !ok {
		return "", errUnknownCommand
	}

	return handler(ctx, params)
}

// ProcessCommand parses a profiler runtime command and returns its response.
func (c *Controller) ProcessCommand(ctx context.Context, request string) (string, error) {
	if c == nil {
		return "", errs.New("profiler is not initialized")
	}

	params := strings.Fields(request)

	return c.executeCommand(ctx, params)
}

func commandWithoutParameters(execute func(context.Context) (string, error)) commandHandler {
	return func(ctx context.Context, params []string) (string, error) {
		if len(params) != 1 {
			return "", errTooManyParameters
		}

		message, err := execute(ctx)
		if err != nil {
			return "", errs.Wrap(err, "cannot execute profiler command")
		}

		return message, nil
	}
}

func (c *Controller) executeSetIntervalCommand(ctx context.Context, params []string) (string, error) {
	if len(params) != 2 { //nolint:mnd
		return "", errInvalidParameterNumber
	}

	seconds, err := strconv.Atoi(params[1])
	if err != nil {
		return "", errFailedToParseInterval
	}

	const maxIntervalSeconds = int(24 * time.Hour / time.Second)

	if seconds < 1 || seconds > maxIntervalSeconds {
		return "", errIntervalOutOfRange
	}

	message, err := c.setInterval(ctx, time.Duration(seconds)*time.Second)
	if err != nil {
		return "", errs.Wrap(err, "cannot set profiler interval")
	}

	return message, nil
}
