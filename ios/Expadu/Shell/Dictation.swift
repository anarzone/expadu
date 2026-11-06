import AVFoundation
import Observation
import Speech

/// Hold to talk to Composer: the words as they're said, and how loud, for the voice bars. Listens
/// only while the button is held; on the iPhone itself when it can.
@Observable
final class Dictation {
    enum State: Equatable { case idle, listening, unavailable }

    private(set) var state = State.idle
    private(set) var words = ""
    /// 0…1, for the bars.
    private(set) var level: Double = 0
    private var engine: AVAudioEngine?
    private var request: SFSpeechAudioBufferRecognitionRequest?
    private var task: SFSpeechRecognitionTask?

    func start() async {
        words = ""
        level = 0
        guard await Self.allowed(),
              let recognizer = SFSpeechRecognizer(locale: Locale.current) ?? SFSpeechRecognizer(locale: Locale(identifier: "en-GB")),
              recognizer.isAvailable
        else {
            state = .unavailable
            return
        }
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.record, mode: .measurement, options: .duckOthers)
            try session.setActive(true, options: .notifyOthersOnDeactivation)
        } catch {
            state = .unavailable
            return
        }
        let request = SFSpeechAudioBufferRecognitionRequest()
        request.shouldReportPartialResults = true
        if recognizer.supportsOnDeviceRecognition { request.requiresOnDeviceRecognition = true }
        let engine = AVAudioEngine()
        Self.listen(to: engine.inputNode, into: request) { [weak self] level in
            Task { @MainActor in self?.level = level }
        }
        task = Self.recognise(request, with: recognizer) { [weak self] words in
            Task { @MainActor in self?.words = words }
        }
        do {
            engine.prepare()
            try engine.start()
        } catch {
            cancel()
            state = .unavailable
            return
        }
        self.engine = engine
        self.request = request
        state = .listening
    }

    /// Let go: what was said.
    func finish() -> String {
        let said = words.trimmingCharacters(in: .whitespacesAndNewlines)
        stopAudio()
        request?.endAudio()
        task?.finish()
        reset()
        return said
    }

    /// Slid away: nothing is sent.
    func cancel() {
        stopAudio()
        task?.cancel()
        reset()
    }

    private func stopAudio() {
        engine?.stop()
        engine?.inputNode.removeTap(onBus: 0)
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    private func reset() {
        engine = nil
        request = nil
        task = nil
        level = 0
        if state == .listening { state = .idle }
    }

    nonisolated private static func allowed() async -> Bool {
        let speech = await withCheckedContinuation { (done: CheckedContinuation<Bool, Never>) in
            SFSpeechRecognizer.requestAuthorization { done.resume(returning: $0 == .authorized) }
        }
        guard speech else { return false }
        return await AVAudioApplication.requestRecordPermission()
    }

    /// The microphone's buffers go to the recogniser; their loudness to the bars. Runs on the audio thread.
    nonisolated private static func listen(to input: AVAudioInputNode, into request: SFSpeechAudioBufferRecognitionRequest, level: @escaping @Sendable (Double) -> Void) {
        let format = input.outputFormat(forBus: 0)
        input.installTap(onBus: 0, bufferSize: 1024, format: format) { buffer, _ in
            request.append(buffer)
            guard let samples = buffer.floatChannelData?[0], buffer.frameLength > 0 else { return }
            var sum: Float = 0
            for index in 0..<Int(buffer.frameLength) { sum += samples[index] * samples[index] }
            let rms = sqrt(sum / Float(buffer.frameLength))
            level(min(1, Double(rms) * 12))
        }
    }

    nonisolated private static func recognise(_ request: SFSpeechAudioBufferRecognitionRequest, with recognizer: SFSpeechRecognizer, words: @escaping @Sendable (String) -> Void) -> SFSpeechRecognitionTask {
        recognizer.recognitionTask(with: request) { result, _ in
            if let result { words(result.bestTranscription.formattedString) }
        }
    }
}
