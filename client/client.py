#!/usr/bin/python2

#Copyright (c) 2013, Tim Lau
#All rights reserved.
#
#Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
#
#    Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
#    Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
#
#THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.


import os,sys,random,tarfile,glob,time,subprocess
import multiprocessing, urllib, shutil
import itertools
import mimetools
import mimetypes
from cStringIO import StringIO
import urllib
import urllib2

#MASTER_IP = 'localhost'
MASTER_IP = '10.10.1.196'
MASTER_SCRIPT = 'index.php'
JOB_FILE = 'workRequest.tar.gz'
USE_HTTP = False
USE_SCP = True
SCP_USER = 'timlau'
SCP_KEY = 'timlau.pem'
UPLOAD_PATH = '/usr/share/nginx/html/work'

job_name = ''
job_path = ''
client_id = 0
total_client = 0

class MultiPartForm(object):
    """Accumulate the data to be used when posting a form."""

    def __init__(self):
        self.form_fields = []
        self.files = []
        self.boundary = mimetools.choose_boundary()
        return
    
    def get_content_type(self):
        return 'multipart/form-data; boundary=%s' % self.boundary

    def add_field(self, name, value):
        """Add a simple field to the form data."""
        self.form_fields.append((name, value))
        return

    def add_file(self, fieldname, filename, fileHandle, mimetype=None):
        """Add a file to be uploaded."""
        body = fileHandle.read()
        if mimetype is None:
            mimetype = mimetypes.guess_type(filename)[0] or 'application/octet-stream'
        self.files.append((fieldname, filename, mimetype, body))
        return
    
    def __str__(self):
        """Return a string representing the form data, including attached files."""
        # Build a list of lists, each containing "lines" of the
        # request.  Each part is separated by a boundary string.
        # Once the list is built, return a string where each
        # line is separated by '\r\n'.  
        parts = []
        part_boundary = '--' + self.boundary
        
        # Add the form fields
        parts.extend(
            [ part_boundary,
              'Content-Disposition: form-data; name="%s"' % name,
              '',
              value,
            ]
            for name, value in self.form_fields
            )
        
        # Add the files to upload
        parts.extend(
            [ part_boundary,
              'Content-Disposition: file; name="%s"; filename="%s"' % \
                 (field_name, filename),
              'Content-Type: %s' % content_type,
              '',
              body,
            ]
            for field_name, filename, content_type, body in self.files
            )
        
        # Flatten the list and add closing boundary marker,
        # then return CR+LF separated data
        flattened = list(itertools.chain(*parts))
        flattened.append('--' + self.boundary + '--')
        flattened.append('')
        return '\r\n'.join(flattened)

def requestWork(name):
  urllib.urlretrieve("http://{0:s}/{1:s}?action=request".format(MASTER_IP, MASTER_SCRIPT), name)

def untar(file):
  if not tarfile.is_tarfile(file):
    return False

  global job_name
  f = tarfile.open(file)
  
  for tarinfo in f:
    if tarinfo.isreg() and tarinfo.name.endswith('.poly'):
      job_name = tarinfo.name.replace('.poly', '')
  global job_path
  job_path = "{0:s}".format(job_name)
  if not os.path.exists(job_path):
    os.makedirs(job_path)
  f.extractall(job_path)
  f.close()
  return True

def retrieveClientID():
  global client_id
  global total_client
  f = open("{0:s}/client.id".format(job_path), 'r')
  client_id = int(f.readline().replace("\n", ""))
  total_client = int(f.readline().replace("\n", ""))

def tar(path):
  os.chdir(sys.path[0] + "/" + path)
  with tarfile.open("../file.tar.gz", "w:gz") as tar:
    for name in os.listdir('.'):
      #if os.path.isfile(name):
    #print(name)
      tar.add(name)
  os.chdir(sys.path[0])
  return

def uploadHTTP(workfile):
  form = MultiPartForm()
   
  # Add a fake file
  form.add_file('workfile', workfile, open(workfile, 'r'))

  # Build the request
  request = urllib2.Request('http://localhost/index.php?action=uploadingWork')
  request.add_header('User-agent', 'buhaha (lalala)')
  body = str(form)
  request.add_header('Content-type', form.get_content_type())
  request.add_header('Content-length', len(body))
  request.add_data(body)

  print 'OUTGOING DATA:'
  print request.get_data()
  print 'SERVER RESPONSE:'
  print urllib2.urlopen(request).read()

  return

def uploadSCP(job_name, path):
  print "Uploading thru SCP"
  target = SCP_USER + "@" + MASTER_IP + ":" + UPLOAD_PATH + "/" + job_name + "/done"
  src_path = "scp -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i " + SCP_KEY + " " + path + "/spairs.* " + target
  print src_path
  #Silly subproces doesn't glob the asterisks in the argument. we need to force it thru the shell
  #ret = subprocess.Popen(["scp", "-i", SCP_KEY, path, target])
  ret = subprocess.call([src_path], shell=True)
  return

def cleanup(folder):
  if os.path.exists('file.tar.gz'):
    os.remove('file.tar.gz')
  if os.path.exists(JOB_FILE):
    os.remove(JOB_FILE)
  shutil.rmtree(folder)
  return

def main():
  while True:
    loop = True
    while loop:
      requestWork(JOB_FILE)
      loop = not untar(JOB_FILE)
    retrieveClientID()

    path = os.getcwd() + "/" + job_path
    print "{0:s}.poly".format(job_name)
    ret = subprocess.Popen(["../factmsieve_client.py", "{0:s}.poly".format(job_name), str(multiprocessing.cpu_count()), str(client_id), str(total_client)], cwd = path)
    ret.wait()
    print ("\n" + path + "\n")
    #if ret.returncode is 10:
    if USE_HTTP:
      tar(job_path)
      uploadHTTP('file.tar.gz')
    elif USE_SCP:
      uploadSCP(job_name, path)
    #else:
    #  sys.exit(1)
    cleanup(job_path)

if __name__ == "__main__":
    main()
