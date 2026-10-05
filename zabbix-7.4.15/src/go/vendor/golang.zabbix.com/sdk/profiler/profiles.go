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
	"path/filepath"
	"runtime/pprof"
	"slices"
	"time"

	"golang.zabbix.com/sdk/errs"
	"golang.zabbix.com/sdk/log"
)

const (
	profileFileMode = 0o600
	profileDirMode  = 0o700
	timeFormat      = "20060102_150405.000000000"

	profileCPU = "cpu"
)

func (s *state) dump() error {
	err := s.stopCPUProfileWithRotation()
	snapshotErr := s.dumpProfilesExcludingCPUWithRotation()

	if err != nil {
		return err
	}

	return snapshotErr
}

func (s *state) dumpPeriodic() error {
	err := s.dump()

	startErr := s.startPeriodicCPUProfile()
	if startErr != nil {
		log.Errf("profiler: cannot start the next periodic CPU profile collection: %s", startErr.Error())
	}

	return err
}

func (s *state) stopCPUProfileWithRotation() error {
	err := os.MkdirAll(s.dir, profileDirMode)
	if err != nil {
		return errs.Wrap(err, "cannot create profiler directory")
	}

	if s.cpuFile == nil {
		return nil
	}

	// The active CPU profile is hidden and does not count toward the configured number of completed files. Make room
	// before publishing it so a failed rotation does not allow the visible profile count to grow without limit.
	err = s.removeOldestProfileFiles(profileCPU, s.maxFiles-1)
	if err != nil {
		return err
	}

	return s.stopCPUProfile()
}

func (s *state) dumpProfilesExcludingCPUWithRotation() error {
	profiles := profilesExcludingCPU()
	timestamp := time.Now().Format(timeFormat)
	profilesToWrite := make([]string, 0, len(profiles))

	var firstErr error

	for _, profile := range profiles {
		err := s.removeOldestProfileFiles(profile, s.maxFiles-1)
		if err != nil {
			if firstErr == nil {
				firstErr = err
			}

			continue
		}

		profilesToWrite = append(profilesToWrite, profile)
	}

	writeErr := s.writeProfiles(profilesToWrite, timestamp)

	if firstErr != nil {
		return firstErr
	}

	return writeErr
}

func (s *state) writeProfiles(profiles []string, timestamp string) error {
	var firstErr error

	for _, profile := range profiles {
		err := s.writeProfile(profile, timestamp)
		if err != nil && firstErr == nil {
			firstErr = err
		}
	}

	return firstErr
}

func (s *state) startCPUProfile() error {
	if s.cpuFile != nil {
		return nil
	}

	cpuProfilePath := s.profilePath(profileCPU, time.Now().Format(timeFormat))

	err := s.startCPUProfileAt(cpuProfilePath)
	if err != nil {
		return err
	}

	return nil
}

func (s *state) startPeriodicCPUProfile() error {
	err := s.startCPUProfile()
	if err != nil {
		setExtensiveProfiling(false)

		return err
	}

	setExtensiveProfiling(true)

	return nil
}

func (s *state) startCPUProfileAt(path string) error {
	if s.cpuFile != nil {
		return nil
	}

	temporaryPath := temporaryCPUProfilePath(path)

	file, err := os.OpenFile(temporaryPath, os.O_RDWR|os.O_CREATE|os.O_TRUNC, profileFileMode) //nolint:gosec
	if err != nil {
		return errs.Wrap(err, "cannot create CPU profile file")
	}

	err = pprof.StartCPUProfile(file)
	if err != nil {
		closeErr := file.Close()
		if closeErr != nil {
			log.Debugf("cannot close CPU profile file: %s", closeErr.Error())
		}

		removeErr := os.Remove(temporaryPath)
		if removeErr != nil && !os.IsNotExist(removeErr) {
			log.Debugf("cannot remove temporary CPU profile file: %s", removeErr.Error())
		}

		return errs.Wrap(err, "cannot start CPU profile")
	}

	s.cpuFile = file
	s.cpuProfilePath = path

	return nil
}

func (s *state) stopCPUProfile() error {
	if s.cpuFile == nil {
		return nil
	}

	pprof.StopCPUProfile()

	err := s.cpuFile.Close()
	s.cpuFile = nil

	if err != nil {
		return errs.Wrap(err, "cannot close CPU profile file")
	}

	err = os.Rename(temporaryCPUProfilePath(s.cpuProfilePath), s.cpuProfilePath)
	s.cpuProfilePath = ""

	if err != nil {
		return errs.Wrap(err, "cannot publish CPU profile file")
	}

	return nil
}

func (s *state) writeProfile(name, timestamp string) error {
	profile := pprof.Lookup(name)
	if profile == nil {
		return errs.Wrapf(errs.New("profile is not available"), "profile %q", name)
	}

	file, err := os.OpenFile(
		s.profilePath(name, timestamp), os.O_RDWR|os.O_CREATE|os.O_TRUNC, profileFileMode,
	)
	if err != nil {
		return errs.Wrapf(err, "cannot create %s profile file", name)
	}

	defer func() {
		closeErr := file.Close()
		if closeErr != nil {
			log.Debugf("cannot close profile file: %s", closeErr.Error())
		}
	}()

	err = profile.WriteTo(file, 0)
	if err != nil {
		return errs.Wrapf(err, "cannot write %s profile", name)
	}

	return nil
}

func (s *state) removeOldestProfileFiles(profile string, filesToKeep int) error {
	files, err := filepath.Glob(filepath.Join(s.dir, profile+"_*.pprof"))
	if err != nil {
		return errs.Wrapf(err, "cannot list %s profile files", profile)
	}

	if len(files) <= filesToKeep {
		return nil
	}

	slices.Sort(files)

	for _, file := range files[:len(files)-filesToKeep] {
		err = os.Remove(file)
		if err != nil && !os.IsNotExist(err) {
			return errs.Wrapf(err, "cannot remove old profile file %s", file)
		}
	}

	return nil
}

func (s *state) profilePath(profile, timestamp string) string {
	return filepath.Join(s.dir, fmt.Sprintf("%s_%s.pprof", profile, timestamp))
}

func temporaryCPUProfilePath(path string) string {
	return filepath.Join(filepath.Dir(path), "."+filepath.Base(path)+".tmp")
}

func profilesExcludingCPU() []string {
	// By default pprof.Profiles returns allocs, block, goroutine, heap, mutex, and threadcreate.
	// It also includes any profiles registered with pprof.NewProfile.
	profiles := pprof.Profiles()
	names := make([]string, 0, len(profiles))

	for _, profile := range profiles {
		names = append(names, profile.Name())
	}

	return names
}
