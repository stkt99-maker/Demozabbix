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
	"fmt"
	"os"
	"runtime"
	"time"

	"golang.zabbix.com/sdk/errs"
	"golang.zabbix.com/sdk/log"
)

const (
	extensiveProfilingEnabled  = true
	onDemandCPUProfileSeconds  = 5
	onDemandCPUProfileDuration = onDemandCPUProfileSeconds * time.Second
)

const (
	actionEnable      action = "enable"
	actionDisable     action = "disable"
	actionExecute     action = "execute"
	actionSetInterval action = "set_interval"
)

const (
	timerStopped timerPurpose = iota
	timerPeriodicDump
	timerExecuteFinish
)

type action string

type timerPurpose uint8

type state struct {
	dir             string
	maxFiles        int
	periodicEnabled bool
	interval        time.Duration
	cpuFile         *os.File
	cpuProfilePath  string
	timer           *time.Timer
	timerPurpose    timerPurpose
}

func newState(options Options) state {
	return state{
		dir:             options.Dir,
		maxFiles:        options.MaxFilesPerProfile,
		periodicEnabled: options.Enabled,
		interval:        options.Interval,
	}
}

func (s *state) start() {
	if !s.periodicEnabled {
		return
	}

	err := s.enable()
	if err != nil {
		log.Errf("profiler: cannot start: %s", err.Error())
	}
}

func (s *state) stop() {
	if s.timerPurpose == timerExecuteFinish {
		s.stopTimer()

		err := s.dump()
		if err != nil {
			log.Errf("profiler: cannot finish on-demand profiling during shutdown: %s", err.Error())
		}

		s.periodicEnabled = false

		setExtensiveProfiling(false)

		return
	}

	err := s.stopRequest()
	if err != nil {
		log.Errf("profiler: cannot stop: %s", err.Error())
	}
}

func (s *state) handle(req request) (string, error) {
	if s.timerPurpose == timerExecuteFinish {
		return "", errProfilerBusy
	}

	switch req.action {
	case actionEnable:
		return s.enableRequest()
	case actionDisable:
		return s.disableRequest()
	case actionExecute:
		return s.executeRequest()
	case actionSetInterval:
		return s.setIntervalRequest(req.interval)
	default:
		return "", errs.New("unknown profiler action")
	}
}

func (s *state) enableRequest() (string, error) {
	if s.periodicEnabled {
		// Do not use %q because escaped backslashes would make the reported profile path inaccurate.
		//nolint:gocritic // The explicit quotes preserve the path exactly as configured.
		return fmt.Sprintf("profiler: already started; profile files are stored in \"%s\"", s.dir), nil
	}

	err := s.enable()
	if err != nil {
		s.stopTimer()
		s.periodicEnabled = false

		return "", err
	}

	log.Infof("profiler: started")

	// Do not use %q because escaped backslashes would make the reported profile path inaccurate.
	//nolint:gocritic // The explicit quotes preserve the path exactly as configured.
	return fmt.Sprintf("profiler: started; profile files will be stored in \"%s\"", s.dir), nil
}

func (s *state) disableRequest() (string, error) {
	if !s.periodicEnabled {
		return "profiler: already stopped", nil
	}

	err := s.disable()
	if err != nil {
		return "", err
	}

	log.Infof("profiler: stopped")

	return "profiler: stopped", nil
}

func (s *state) executeRequest() (string, error) {
	if s.periodicEnabled {
		s.stopTimer()
	} else {
		err := os.MkdirAll(s.dir, profileDirMode)
		if err != nil {
			return "", errs.Wrap(err, "cannot create profiler directory")
		}
	}

	setExtensiveProfiling(true)

	cleanupRequired := true

	defer func() {
		if cleanupRequired {
			setExtensiveProfiling(false)
			s.restorePeriodicProfiling()
		}
	}()

	err := s.stopCPUProfileWithRotation()
	if err != nil {
		return "", err
	}

	err = s.startCPUProfile()
	if err != nil {
		return "", err
	}

	s.startExecuteTimer()

	cleanupRequired = false

	return fmt.Sprintf(
		"profiler: collecting CPU profile; results will be written to \"%s\" in %d seconds; "+
			"CPU data will cover the next %d seconds; heap, allocs, goroutine, block, mutex, and threadcreate "+
			"profiles will also be written there; for more precise CPU data, enable periodic profiling with %s",
		s.dir,
		onDemandCPUProfileSeconds,
		onDemandCPUProfileSeconds,
		commandEnable,
	), nil
}

func (s *state) finishExecute() {
	err := s.dump()
	if err != nil {
		log.Errf("profiler: cannot write on-demand profiles: %s", err.Error())
	} else {
		log.Infof(
			"profiler: profiles written to \"%s\"; CPU profile collected over the last %d seconds",
			s.dir,
			onDemandCPUProfileSeconds,
		)
	}

	s.restorePeriodicProfiling()
}

func (s *state) restorePeriodicProfiling() {
	if s.periodicEnabled {
		err := s.startPeriodicCPUProfile()
		if err != nil {
			log.Errf("profiler: cannot resume periodic CPU profile collection: %s", err.Error())
		}

		s.resetPeriodicTimer()

		return
	}

	setExtensiveProfiling(false)
}

func (s *state) setIntervalRequest(interval time.Duration) (string, error) {
	if interval <= 0 {
		return "", errs.New("interval must be greater than 0")
	}

	s.interval = interval
	if s.periodicEnabled {
		s.resetPeriodicTimer()
	}

	message := fmt.Sprintf("profiler: interval set to %d seconds", int(interval.Seconds()))
	log.Infof("%s", message)

	return message, nil
}

func (s *state) stopRequest() error {
	if !s.periodicEnabled {
		return nil
	}

	err := s.disable()
	if err != nil {
		return err
	}

	return nil
}

func (s *state) enable() error {
	s.periodicEnabled = true
	s.resetPeriodicTimer()

	err := os.MkdirAll(s.dir, profileDirMode)
	if err != nil {
		return errs.Wrap(err, "cannot create profiler directory")
	}

	return s.startPeriodicCPUProfile()
}

func (s *state) disable() error {
	s.stopTimer()

	err := s.dump()

	setExtensiveProfiling(false)

	s.periodicEnabled = false

	if err != nil {
		return err
	}

	return nil
}

func (s *state) timerChannel() <-chan time.Time {
	if s.timer == nil {
		return nil
	}

	return s.timer.C
}

func (s *state) processTimer() {
	purpose := s.timerPurpose
	s.stopTimer()

	switch purpose {
	case timerExecuteFinish:
		s.finishExecute()
	case timerPeriodicDump:
		s.processPeriodicTimer()
	default:
		log.Debugf("profiler: timer fired for unknown purpose %d", purpose)
	}
}

func (s *state) processPeriodicTimer() {
	err := s.dumpPeriodic()
	if err != nil {
		log.Errf("profiler: cannot write profiles: %s", err.Error())
	}

	if s.periodicEnabled {
		s.resetPeriodicTimer()
	}
}

func (s *state) resetPeriodicTimer() {
	s.startTimer(timerPeriodicDump, s.interval)
}

func (s *state) startExecuteTimer() {
	s.startTimer(timerExecuteFinish, onDemandCPUProfileDuration)
}

func (s *state) startTimer(purpose timerPurpose, duration time.Duration) {
	s.stopTimer()
	s.timer = time.NewTimer(duration)
	s.timerPurpose = purpose
}

func (s *state) stopTimer() {
	if s.timer != nil {
		s.timer.Stop()
		s.timer = nil
	}

	s.timerPurpose = timerStopped
}

func setExtensiveProfiling(enabled bool) {
	if !extensiveProfilingEnabled {
		return
	}

	rate := 0
	if enabled {
		rate = 1
	}

	runtime.SetBlockProfileRate(rate)
	runtime.SetMutexProfileFraction(rate)
}
