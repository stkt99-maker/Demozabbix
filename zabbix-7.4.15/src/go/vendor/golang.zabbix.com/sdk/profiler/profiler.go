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
	"time"

	"golang.zabbix.com/sdk/errs"
)

// Options contains periodic profiler settings.
type Options struct {
	Enabled            bool
	Dir                string
	MaxFilesPerProfile int
	Interval           time.Duration
}

// Controller serializes profiler state changes and profile writes.
type Controller struct {
	options  Options
	handlers map[string]commandHandler
	requests chan request
	done     chan struct{}
}

type request struct {
	action   action
	interval time.Duration
	reply    chan response
}

type response struct {
	message string
	err     error
}

// New creates a stopped profiler controller.
func New(options Options) *Controller {
	c := &Controller{
		options:  options,
		requests: make(chan request),
		done:     make(chan struct{}),
	}

	c.handlers = map[string]commandHandler{
		commandEnable:      commandWithoutParameters(c.enable),
		commandDisable:     commandWithoutParameters(c.disable),
		commandExecute:     commandWithoutParameters(c.execute),
		commandSetInterval: c.executeSetIntervalCommand,
	}

	return c
}

// Start runs the profiler controller until context cancellation.
func (c *Controller) Start(ctx context.Context) {
	go c.run(ctx)
}

// Wait waits for the profiler controller to stop.
func (c *Controller) Wait() {
	<-c.done
}

func (c *Controller) enable(ctx context.Context) (string, error) {
	return c.call(ctx, request{action: actionEnable})
}

func (c *Controller) disable(ctx context.Context) (string, error) {
	return c.call(ctx, request{action: actionDisable})
}

func (c *Controller) execute(ctx context.Context) (string, error) {
	return c.call(ctx, request{action: actionExecute})
}

func (c *Controller) setInterval(ctx context.Context, interval time.Duration) (string, error) {
	return c.call(ctx, request{action: actionSetInterval, interval: interval})
}

func (c *Controller) call(ctx context.Context, req request) (string, error) {
	// req is passed by value, so every concurrent call gets its own reply channel.
	req.reply = make(chan response, 1)

	select {
	case c.requests <- req:
	case <-ctx.Done():
		return "", errs.Wrap(ctx.Err(), "cannot send profiler request")
	case <-c.done:
		return "", errProfilerStopped
	}

	select {
	case resp := <-req.reply:
		if resp.err != nil {
			return "", resp.err
		}

		return resp.message, nil
	case <-ctx.Done():
		return "", errs.Wrap(ctx.Err(), "cannot wait for profiler response")
	case <-c.done:
		return "", errProfilerStopped
	}
}

func (c *Controller) run(ctx context.Context) {
	defer close(c.done)

	s := newState(c.options)
	s.start()

	for {
		select {
		case <-s.timerChannel():
			s.processTimer()
		case req := <-c.requests:
			message, err := s.handle(req)
			req.reply <- response{message: message, err: err}
		case <-ctx.Done():
			s.stop()

			return
		}
	}
}
