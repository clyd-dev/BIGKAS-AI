@extends('layouts.student')
@section('title', 'Reading Assessment')
@section('content')
<div class="container mt-4 text-center">
    <h2>{{ $material->title }}</h2>
    
    <div id="setup-section">
        <p>When you are ready, click Start Recording and read the passage below.</p>
        <button id="btnStartLearner" class="btn btn-danger btn-lg rounded-pill px-4">
            <i class="bi bi-mic-fill"></i> Start Recording
        </button>
    </div>

    <div id="recording-section" class="d-none mt-3">
        <h4 class="text-danger blink">🔴 Recording...</h4>
        <div class="reading-passage p-4 bg-light rounded text-start mx-auto mt-4" style="max-width: 800px; font-size: 1.5rem; line-height: 2;">
            {{ $material->content }}
        </div>
        <button id="btnFinishLearner" class="btn btn-success btn-lg mt-4 px-4 rounded-pill">
            <i class="bi bi-check-circle"></i> I'm Done!
        </button>
    </div>

    <div id="uploading-section" class="d-none mt-3">
        <h4>Uploading your reading...</h4>
        <div class="spinner-border text-primary" role="status"></div>
    </div>
</div>

<script>
    let mediaRecorder;
    let audioChunks = [];

    document.getElementById('btnStartLearner').addEventListener('click', async () => {
        // 1. Tell server we started
        await fetch(`{{ route('student.assessment.start', $assessment->id) }}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });

        // 2. Start recording
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
        mediaRecorder.start();

        document.getElementById('setup-section').classList.add('d-none');
        document.getElementById('recording-section').classList.remove('d-none');
    });

    document.getElementById('btnFinishLearner').addEventListener('click', () => {
        document.getElementById('recording-section').classList.add('d-none');
        document.getElementById('uploading-section').classList.remove('d-none');

        mediaRecorder.onstop = async () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const formData = new FormData();
            formData.append('audio', audioBlob, 'recording.webm');

            try {
                let response = await fetch(`{{ route('student.assessment.upload-audio', $assessment->id) }}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                
                let data = await response.json();
                
                if (response.ok) {
                    alert('Great job! Your reading was sent to your teacher.');
                    window.location.href = '{{ route("student.dashboard") }}';
                } else {
                    alert('Error uploading: ' + (data.message || 'Unknown error'));
                    document.getElementById('uploading-section').classList.add('d-none');
                    document.getElementById('setup-section').classList.remove('d-none'); // allow retry maybe?
                }
            } catch (err) {
                alert('Upload failed: ' + err.message);
                document.getElementById('uploading-section').classList.add('d-none');
                document.getElementById('setup-section').classList.remove('d-none');
            }
        };
        
        mediaRecorder.stop();
    });
</script>
@endsection